<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\UnitResource;
use App\Models\Employee;
use App\Models\Unit;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage the current tenant's employees. Every action here is reached only
 * by an authenticated, verified user; EmployeePolicy (via Gate) is the actual
 * authority on whether they may see or change anything below.
 */
class EmployeeController extends Controller
{
    /** A large agency (a hospital, task-6-brief.md's Shape C) can hold far more employees than it has system users, so unlike units this list is genuinely paged. */
    private const PER_PAGE = 25;

    /** @var array<int, string>|null Memoized: the tag vocabulary is asked for twice on a filtered request. */
    private ?array $tags = null;

    public function __construct(private Tenant $tenant) {}

    /**
     * List the current tenant's employees.
     *
     * Four filters, all in the query string so the list is a link:
     *
     * | Key      | Means                                                     |
     * | -------- | --------------------------------------------------------- |
     * | `search` | Scout, across every column toSearchableArray() indexes    |
     * | `unit`   | currently deployed in this unit **or any unit under it**  |
     * | `tag`    | carries this tag                                          |
     * | `exempt` | no daily time record expected                             |
     *
     * `unit` includes the subtree because that is what choosing a unit means
     * in this product (01-organization.md rule 4, and Unit::descendants() is
     * the documented way to answer it): a department whose people all sit in
     * its divisions would otherwise return nothing at all. `unit` and `tag`
     * are both whitelisted against what the tenant actually has, the same way
     * AgencyController::index whitelists `sort` — a mangled query string
     * drops the filter and reports it as unset, rather than showing a list
     * filtered by a value the picker cannot display.
     *
     * The three new filters live inside ->query(), not as Scout ->where()
     * clauses, because none of them is a scalar column match: the unit filter
     * is an EXISTS against the open deployment and the tag filter is a jsonb
     * containment test. Under the shipped `database` engine that closure is
     * applied to the very query ->paginate() counts and pages
     * (DatabaseEngine::buildSearchQuery -> addAdditionalConstraints, which
     * calls $builder->queryCallback), so the totals and the page agree. An
     * external engine (Meilisearch, Algolia, Typesense) would match against
     * its own index and apply this closure only when rehydrating, so the
     * hydrated rows would be right but the total and the page boundaries
     * would count unfiltered matches. That is the same class of gap R10
     * records for agency_id and it is why the tenant filter below is a Scout
     * ->where() rather than part of this closure: correctness across
     * tenants cannot depend on the engine, correctness of a page count can.
     *
     * Search runs through Scout (Employee uses Searchable) rather than a
     * plain whereLike, because a name is split across the first/middle/last/
     * suffix columns toSearchableArray() already indexes together.
     * ->where('agency_id', ...) is explicit and load-bearing (R10,
     * .ai/rules/models.md): SCOUT_DRIVER=database happens to also carry
     * AgencyScope, because DatabaseEngine::newSearchQuery() falls back to
     * Model::newQuery() — but an external engine (Meilisearch, Algolia,
     * Typesense) matches against its own index and only scopes the
     * rehydration, so an unfiltered search would leak another agency's row
     * counts, ordering and pagination even though the hydrated rows
     * themselves would still come back correct. See EmployeeControllerTest
     * for the cross-tenant proof.
     *
     * currentDeployment.unit is eager-loaded through ->query() — for the
     * database engine (the only one configured today) its closure applies
     * directly to the underlying Eloquent query, including for eager
     * loading. This is deliberate, not decorative: Model::shouldBeStrict()
     * arms the lazy-loading guard on a hydrated collection only when it
     * holds more than one model (Builder::hydrate()), so this trap cannot
     * be caught by a single-employee test — only a page of two or more.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Employee::class);

        $search = $request->string('search')->trim()->toString();
        $exempt = $request->boolean('exempt');

        $tag = $request->string('tag')->trim()->toString();
        $tag = $tag !== '' && in_array($tag, $this->tags(), true) ? $tag : '';

        $unit = ($id = $request->string('unit')->trim()->toString()) === '' ? null : Unit::find($id);
        $unitIds = $unit === null ? null : [$unit->id, ...$unit->descendants()->pluck('id')->all()];

        $employees = Employee::search($search)
            ->where('agency_id', $this->tenant->id())
            ->query(fn (Builder $query) => $query
                ->with('currentDeployment.unit')
                ->when($unitIds !== null, fn (Builder $query) => $query->whereHas(
                    'currentDeployment',
                    fn (Builder $deployment) => $deployment->whereIn('unit_id', $unitIds),
                ))
                ->when($tag !== '', fn (Builder $query) => $query->whereJsonContains('tags', $tag))
                ->when($exempt, fn (Builder $query) => $query->where('exempt', true))
                ->orderBy('last_name')->orderBy('first_name'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('employees/index', [
            'employees' => EmployeeResource::collection($employees->getCollection())->resolve(),
            'pagination' => [
                'from' => $employees->firstItem(),
                'to' => $employees->lastItem(),
                'total' => $employees->total(),
                'previous' => $employees->previousPageUrl(),
                'next' => $employees->nextPageUrl(),
            ],
            'filters' => [
                'search' => $search,
                'unit' => $unit?->id ?? '',
                'tag' => $tag,
                'exempt' => $exempt,
            ],
            // Closures, so the filter controls' own options are not re-queried
            // on every keystroke: the front end reloads only `employees`,
            // `pagination` and `filters`, and Inertia never invokes a closure
            // for a prop a partial reload excluded.
            'units' => fn () => UnitResource::collection($this->units())->resolve(),
            'tags' => fn () => $this->tags(),
        ]);
    }

    /**
     * Every tag any of this tenant's employees carries, once each, sorted.
     *
     * One query rather than pulling every employee's `tags` into PHP and
     * flattening: a hospital (task-6-brief.md's Shape C) has thousands of
     * rows and a handful of distinct tags. jsonb_array_elements_text unnests
     * the array server-side, and going through Employee::query() rather than
     * DB::table keeps AgencyScope and the soft-delete scope on it — a removed
     * employee's tags are not the agency's vocabulary any more.
     *
     * ->where('agency_id', ...) is explicit on top of AgencyScope for the
     * same reason it is explicit on the search above: this query replaces
     * Eloquent's select list with a raw expression and never hydrates a
     * model, so it is exactly the shape where a scope going missing would
     * not be noticed. It also keeps one invariant true of this controller —
     * every query it sends against `employees` names the agency twice —
     * which is what EmployeeControllerTest asserts on the SQL itself.
     *
     * @return array<int, string>
     */
    private function tags(): array
    {
        return $this->tags ??= Employee::query()
            ->where('agency_id', $this->tenant->id())
            ->select(DB::raw('DISTINCT jsonb_array_elements_text(tags) AS tag'))
            ->orderBy('tag')
            ->pluck('tag')
            ->all();
    }

    /**
     * The tenant's whole unit tree, flat. The front end composes the nesting
     * from `parent_id` (resources/js/lib/units.ts), which is why this is one
     * ordered list and not a recursive query — see UnitResource's docblock.
     *
     * @return Collection<int, Unit>
     */
    private function units(): Collection
    {
        return Unit::query()->orderBy('name')->get();
    }

    public function create(): Response
    {
        Gate::authorize('create', Employee::class);

        return Inertia::render('employees/create');
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $employee = Employee::create($request->validated());

        return redirect()->route('employees.index')->with('success', "{$employee->name} added.");
    }

    /** Deployment history as a table, not a decorative timeline (task-6-brief.md). */
    public function show(Employee $employee): Response
    {
        Gate::authorize('view', $employee);

        $employee->load([
            'currentDeployment.unit',
            'deployments' => fn ($query) => $query->with('unit')->orderByDesc('starts'),
        ]);

        return Inertia::render('employees/show', [
            'employee' => EmployeeResource::make($employee)->resolve(),
            // The tree, for two things the screen does with it: the move
            // sheet's picker, and the ancestry line under the current unit
            // ("Office of the Executive Director / Administrative Division /
            // Records Section"). It is deliberately NOT gated on `update`
            // even though only a manager sees the sheet: the same tree is
            // already on /units for anyone holding organization.view, so
            // withholding it here would protect nothing and would cost a
            // view-only reader the one line that says where the unit sits.
            'units' => UnitResource::collection($this->units())->resolve(),
        ]);
    }

    public function edit(Employee $employee): Response
    {
        Gate::authorize('update', $employee);

        return Inertia::render('employees/edit', [
            'employee' => EmployeeResource::make($employee)->resolve(),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $employee->update($request->validated());

        return redirect()->route('employees.index')->with('success', "{$employee->name} updated.");
    }

    /** $employee->delete() is a soft delete (SoftDeletes): an UPDATE, not a DELETE, so it never trips units.head_id / deployments.employee_id's ON DELETE RESTRICT. */
    public function destroy(Employee $employee): RedirectResponse
    {
        Gate::authorize('delete', $employee);

        $employee->delete();

        return redirect()->route('employees.index')->with('success', "{$employee->name} removed.");
    }
}

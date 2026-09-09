<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function __construct(private Tenant $tenant) {}

    /**
     * List the current tenant's employees.
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

        $employees = Employee::search($search)
            ->where('agency_id', $this->tenant->id())
            ->query(fn (Builder $query) => $query->with('currentDeployment.unit')->orderBy('last_name')->orderBy('first_name'))
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
            'filters' => ['search' => $search],
        ]);
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

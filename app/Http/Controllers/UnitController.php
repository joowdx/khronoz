<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\UnitResource;
use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage the current tenant's units. Every action here is reached only by an
 * authenticated, verified user; UnitPolicy (via Gate) is the actual authority
 * on whether they may see or change anything below. No show route: units are
 * a tree drawn from the index, not individually paged screens
 * (task-6-brief.md).
 */
class UnitController extends Controller
{
    /**
     * List every unit of the current tenant, flat with `parent_id` so the
     * front end can compose the tree (task-6-brief.md's units/index — "a
     * tree is not a table", no precedent to page against). A realistic
     * agency's whole org chart is one screen, unlike the employees or users
     * lists, so this is the one index in the app with no pagination.
     *
     * Unit::query() needs no explicit tenant filter the way Employee::search()
     * does — AgencyScope (BelongsToAgency) applies to every plain Eloquent
     * read automatically; Employee's Scout search is the exception because
     * Scout bypasses Eloquent scopes for a non-database engine, not Unit.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', Unit::class);

        $units = Unit::query()
            ->with('head')
            // How many people are in this unit right now: one aggregate on
            // the same query, scoped to the open deployment. A unit is only
            // worth drawing because people are in it, and counting per row
            // would be one SELECT per unit (N+1). The alias is `people`
            // rather than `deployments` because the number is a headcount,
            // not a count of history rows.
            ->withCount([
                'deployments as people_count' => fn (Builder $query) => $query->whereNull('ends'),
                // Every placement it has ever held, closed ones included.
                // That, with the child count the tree already knows, is what
                // decides whether Remove can be offered at all:
                // deployments_unit_id_agency_id_foreign RESTRICTs. The route
                // translates that refusal too (see destroy), so a stale index
                // page gets a message rather than a 500 — this count is what
                // keeps the action from being offered in the first place.
                'deployments',
            ])
            ->orderBy('name')
            ->get();

        return Inertia::render('units/index', [
            'units' => UnitResource::collection($units)->resolve(),
            'employees' => fn () => $this->heads(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Unit::class);

        return Inertia::render('units/create', [
            'units' => UnitResource::collection($this->tree())->resolve(),
            'employees' => fn () => $this->heads(),
        ]);
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        $unit = Unit::create($request->validated());

        return redirect()->route('units.index')->with('success', "{$unit->name} added.");
    }

    public function edit(Unit $unit): Response
    {
        Gate::authorize('update', $unit);

        return Inertia::render('units/edit', [
            'unit' => UnitResource::make($unit->load('head'))->resolve(),
            'units' => UnitResource::collection($this->tree())->resolve(),
            'employees' => fn () => $this->heads(),
        ]);
    }

    /**
     * Two structural refusals belong to the database here, and this method
     * translates them rather than pre-checking them — the same relationship
     * EmployeeDeploymentController has with `deployments_no_overlap`.
     * StoreUnitRequest's docblock records why: both are pure structural
     * checks with no concurrency angle, and the parent picker already
     * excludes the unit itself, so duplicating them in validation would be a
     * second source of truth for a case the UI does not normally reach.
     *
     * | SQLSTATE | Constraint              | Reached by |
     * | -------- | ----------------------- | ---------- |
     * | P0001    | units_acyclic           | a stale edit page: open Edit for A, move B under A in another tab, then set A's parent to B |
     * | 23514    | units_parent_not_self   | by URL — the picker excludes the unit from its own options |
     *
     * P0001 on this statement can only be `units_acyclic`: the table's other
     * trigger, `agency_not_platform`, fires on `UPDATE OF agency_id`, and
     * `agency_id` is not in UpdateUnitRequest's rules. 23514 can only be
     * `units_parent_not_self`, the one CHECK the table carries. Anything else
     * is a real failure and re-throws untouched.
     *
     * The write is wrapped in its own transaction so the refusal is
     * recoverable rather than merely caught: a failed statement leaves the
     * surrounding transaction aborted (25P02) and every later query in it
     * refused, so a translation that only catches the exception would report
     * a tidy validation error on a connection nothing else can use. Outside a
     * transaction Postgres' autocommit hides that; inside one — the test
     * suite, or any future caller that opens one — it does not. MoveEmployee
     * already transacts for its own reasons, which is why the same
     * translation in EmployeeDeploymentController needs nothing here.
     */
    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        try {
            DB::transaction(fn () => $unit->update($request->validated()));
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                'P0001' => ValidationException::withMessages(['parent_id' => ['Under one of its own units.']]),
                '23514' => ValidationException::withMessages(['parent_id' => ['Cannot be its own parent.']]),
                default => $e,
            };
        }

        return redirect()->route('units.index')->with('success', "{$unit->name} updated.");
    }

    /**
     * The tenant's whole tree, flat, for a parent picker. The front end
     * composes the nesting from `parent_id` (resources/js/lib/units.ts).
     *
     * @return Collection<int, Unit>
     */
    private function tree(): Collection
    {
        return Unit::query()->orderBy('name')->get();
    }

    /**
     * Who may be a unit's head: this tenant's employees, still employed.
     *
     * `separated_at` is the filter and not a nicety — a person who has left
     * cannot run a unit, so offering them is offering a mistake. The whole
     * list travels to the browser because the picker searches client-side
     * (§5.14's popover with cmdk); a very large agency wants a search
     * endpoint instead, which is carried forward rather than guessed at here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function heads(): array
    {
        return EmployeeResource::collection(
            Employee::query()->whereNull('separated_at')->orderBy('last_name')->orderBy('first_name')->get()
        )->resolve();
    }

    /**
     * `units_parent_id_agency_id_foreign` (a child unit) and
     * `deployments_unit_id_agency_id_foreign` (any deployment, closed rows
     * included) both RESTRICT, so a unit still in use is refused at the
     * database with 23001 rather than by a hand-checked guard here.
     *
     * NOT `units_head_id_agency_id_foreign`: `units.head_id` points *out of*
     * `units` at an employee, so it restricts deleting the **employee**, not
     * deleting the unit — and an employee is soft-deleted (an UPDATE), so it
     * never fires at all. MEASURED: removing a unit that has a head returns
     * 302. The tree hides Remove where the two real RESTRICTs would bite
     * (`deployments_count` plus the child count), which is the primary
     * defence and stays; hiding an action is not translating a refusal
     * though, and this route is a live authorized one reachable from a stale
     * index page or by URL. There is no field to hang the message on, so it
     * arrives as a flash error naming what stands in the way.
     */
    public function destroy(Unit $unit): RedirectResponse
    {
        Gate::authorize('delete', $unit);

        try {
            // Its own transaction, so the refusal is recoverable and not just
            // caught — see update() for why.
            DB::transaction(fn () => $unit->delete());
        } catch (QueryException $e) {
            if ($e->getCode() !== '23001') {
                throw $e;
            }

            return redirect()->route('units.index')
                ->with('error', "{$unit->name} cannot be removed while a unit sits under it or anyone has ever been deployed to it.");
        }

        return redirect()->route('units.index')->with('success', "{$unit->name} removed.");
    }
}

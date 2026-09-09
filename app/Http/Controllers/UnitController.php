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
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
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
                // That is what decides whether Remove can be offered at all:
                // deployments_unit_id_agency_id_foreign RESTRICTs, and its
                // refusal is a 500 rather than a message a form can show.
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

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        $unit->update($request->validated());

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

    /** units_parent_id_agency_id_foreign and units_head_id_agency_id_foreign both RESTRICT, so a unit still in use refuses this at the database (23001) rather than needing a hand-checked guard here. */
    public function destroy(Unit $unit): RedirectResponse
    {
        Gate::authorize('delete', $unit);

        $unit->delete();

        return redirect()->route('units.index')->with('success', "{$unit->name} removed.");
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
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

        $units = Unit::query()->with('head')->orderBy('name')->get();

        return Inertia::render('units/index', [
            'units' => UnitResource::collection($units)->resolve(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Unit::class);

        return Inertia::render('units/create');
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
        ]);
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        $unit->update($request->validated());

        return redirect()->route('units.index')->with('success', "{$unit->name} updated.");
    }

    /** units_parent_id_agency_id_foreign and units_head_id_agency_id_foreign both RESTRICT, so a unit still in use refuses this at the database (23001) rather than needing a hand-checked guard here. */
    public function destroy(Unit $unit): RedirectResponse
    {
        Gate::authorize('delete', $unit);

        $unit->delete();

        return redirect()->route('units.index')->with('success', "{$unit->name} removed.");
    }
}

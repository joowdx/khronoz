<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkgroupRequest;
use App\Http\Requests\UpdateWorkgroupRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\WorkgroupResource;
use App\Models\Employee;
use App\Models\Workgroup;
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
 * Manage the current tenant's workgroups. Every action here is reached only by an
 * authenticated, verified user; WorkgroupPolicy (via Gate) is the actual authority
 * on whether they may see or change anything below. No show route: workgroups are
 * a tree drawn from the index, not individually paged screens
 * (task-6-brief.md).
 */
class WorkgroupController extends Controller
{
    /**
     * List every workgroup of the current tenant, flat with `parent_id` so the
     * front end can compose the tree (task-6-brief.md's workgroups/index — "a
     * tree is not a table", no precedent to page against). A realistic
     * agency's whole org chart is one screen, unlike the employees or users
     * lists, so this is the one index in the app with no pagination.
     *
     * Workgroup::query() needs no explicit tenant filter the way Employee::search()
     * does — AgencyScope (BelongsToAgency) applies to every plain Eloquent
     * read automatically; Employee's Scout search is the exception because
     * Scout bypasses Eloquent scopes for a non-database engine, not Workgroup.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', Workgroup::class);

        $workgroups = Workgroup::query()
            ->with('head')
            // How many people are in this workgroup right now: one aggregate on
            // the same query, scoped to the open deployment. A workgroup is only
            // worth drawing because people are in it, and counting per row
            // would be one SELECT per workgroup (N+1). The alias is `people`
            // rather than `deployments` because the number is a headcount,
            // not a count of history rows. This aggregate is each workgroup's
            // own; rollUpPeople() below turns it into the subtree's.
            ->withCount([
                'deployments as people_count' => fn (Builder $query) => $query->whereNull('ends'),
                // Every placement it has ever held, closed ones included.
                // That, with the child count the tree already knows, is what
                // decides whether Remove can be offered at all:
                // deployments_workgroup_id_agency_id_foreign RESTRICTs. The route
                // translates that refusal too (see destroy), so a stale index
                // page gets a message rather than a 500 — this count is what
                // keeps the action from being offered in the first place.
                'deployments',
            ])
            ->orderBy('name')
            ->get();

        $this->rollUpPeople($workgroups);

        return Inertia::render('workgroups/index', [
            'workgroups' => WorkgroupResource::collection($workgroups)->resolve(),
            'employees' => fn () => $this->heads(),
        ]);
    }

    /**
     * Turn each workgroup's own headcount into its subtree's, in place, before the
     * resource resolves.
     *
     * `people_count` is read as "this workgroup and everything under it", because
     * that is what the two things around the number already mean: it is a link
     * to `/employees?workgroup=…`, whose filter expands over Workgroup::descendants()
     * (EmployeeController::index), and 01-organization.md rule 4 makes the
     * subtree the canonical answer to "who is in this workgroup". MEASURED before
     * this existed, on the seeded Demo Agency: Administrative Division
     * displayed 6 and the link it carried reported 15; Office of the Executive
     * Director displayed 5 against 28.
     *
     * One arithmetic pass over the list the query already returned, not a
     * recursive query per row: the whole tenant's tree is in hand (this index
     * has no pagination, deliberately), so the rollup costs O(n) and adds no
     * queries at all. Post-order over an explicit stack, so a workgroup is only
     * added to its parent once its own subtree is complete — each workgroup is
     * pushed exactly twice regardless of depth.
     *
     * A `parent_id` naming a row outside the list is treated as a root, the
     * same convention flattenWorkgroups() applies client-side
     * (resources/js/lib/workgroups.ts), so a future scoped list rolls up within
     * itself rather than losing a subtree — and a cycle the workgroups_acyclic
     * trigger somehow did not refuse is simply never reached from a root
     * rather than spinning here.
     *
     * `deployments_count` is deliberately NOT rolled up. It exists to keep
     * Remove from being offered where deployments_workgroup_id_agency_id_foreign's
     * RESTRICT would bite, and that FK names this workgroup's own rows; a subtree
     * total would hide a removable leaf's zero behind its parent's history.
     *
     * @param  Collection<int, Workgroup>  $workgroups
     */
    private function rollUpPeople(Collection $workgroups): void
    {
        $byId = $workgroups->keyBy('id');

        /** @var array<string, array<int, string>> $children */
        $children = [];

        foreach ($workgroups as $workgroup) {
            $parent = $workgroup->parent_id !== null && $byId->has($workgroup->parent_id) ? $workgroup->parent_id : '';
            $children[$parent][] = $workgroup->id;
        }

        /** @var array<int, array{0: string, 1: bool}> $stack */
        $stack = array_map(fn (string $id): array => [$id, false], $children[''] ?? []);

        while ($stack !== []) {
            [$id, $rolled] = array_pop($stack);

            if ($rolled) {
                foreach ($children[$id] ?? [] as $child) {
                    $byId[$id]->people_count += $byId[$child]->people_count;
                }

                continue;
            }

            $stack[] = [$id, true];

            foreach ($children[$id] ?? [] as $child) {
                $stack[] = [$child, false];
            }
        }
    }

    public function create(): Response
    {
        Gate::authorize('create', Workgroup::class);

        return Inertia::render('workgroups/create', [
            'workgroups' => WorkgroupResource::collection($this->tree())->resolve(),
            'employees' => fn () => $this->heads(),
        ]);
    }

    public function store(StoreWorkgroupRequest $request): RedirectResponse
    {
        $workgroup = Workgroup::create($request->validated());

        return redirect()->route('workgroups.index')->with('success', "{$workgroup->name} added.");
    }

    public function edit(Workgroup $workgroup): Response
    {
        Gate::authorize('update', $workgroup);

        return Inertia::render('workgroups/edit', [
            'workgroup' => WorkgroupResource::make($workgroup->load('head'))->resolve(),
            'workgroups' => WorkgroupResource::collection($this->tree())->resolve(),
            'employees' => fn () => $this->heads(),
        ]);
    }

    /**
     * Two structural refusals belong to the database here, and this method
     * translates them rather than pre-checking them — the same relationship
     * EmployeeDeploymentController has with `deployments_no_overlap`.
     * StoreWorkgroupRequest's docblock records why: both are pure structural
     * checks with no concurrency angle, and the parent picker already
     * excludes the workgroup itself, so duplicating them in validation would be a
     * second source of truth for a case the UI does not normally reach.
     *
     * | SQLSTATE | Constraint              | Reached by |
     * | -------- | ----------------------- | ---------- |
     * | P0001    | workgroups_acyclic           | a stale edit page: open Edit for A, move B under A in another tab, then set A's parent to B |
     * | 23514    | workgroups_parent_not_self   | by URL — the picker excludes the workgroup from its own options |
     *
     * P0001 on this statement can only be `workgroups_acyclic`: the table's other
     * trigger, `agency_not_platform`, fires on `UPDATE OF agency_id`, and
     * `agency_id` is not in UpdateWorkgroupRequest's rules. 23514 can only be
     * `workgroups_parent_not_self`, the one CHECK the table carries. Anything else
     * is a real failure and re-throws untouched.
     *
     * The write is wrapped in its own transaction so the refusal is
     * recoverable rather than merely caught: a failed statement leaves the
     * surrounding transaction aborted (25P02) and every later query in it
     * refused, so a translation that only catches the exception would report
     * a tidy validation error on a connection nothing else can use. Outside a
     * transaction Postgres' autocommit hides that; inside one — the test
     * suite, or any future caller that opens one — it does not. TransferEmployee
     * already transacts for its own reasons, which is why the same
     * translation in EmployeeDeploymentController needs nothing here.
     */
    public function update(UpdateWorkgroupRequest $request, Workgroup $workgroup): RedirectResponse
    {
        try {
            DB::transaction(fn () => $workgroup->update($request->validated()));
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                'P0001' => ValidationException::withMessages(['parent_id' => ['Under one of its own workgroups.']]),
                '23514' => ValidationException::withMessages(['parent_id' => ['Cannot be its own parent.']]),
                default => $e,
            };
        }

        return redirect()->route('workgroups.index')->with('success', "{$workgroup->name} updated.");
    }

    /**
     * The tenant's whole tree, flat, for a parent picker. The front end
     * composes the nesting from `parent_id` (resources/js/lib/workgroups.ts).
     *
     * @return Collection<int, Workgroup>
     */
    private function tree(): Collection
    {
        return Workgroup::query()->orderBy('name')->get();
    }

    /**
     * Who may be a workgroup's head: this tenant's employees, still employed.
     *
     * An open deployment is current employment. Someone with only closed
     * placements, or no placement yet, cannot run a workgroup. The workgroup requests
     * enforce the same condition for IDs submitted outside this picker.
     *
     * @return array<int, array<string, mixed>>
     */
    private function heads(): array
    {
        return EmployeeResource::collection(
            Employee::query()->whereHas('currentDeployment')->orderBy('last_name')->orderBy('first_name')->get()
        )->resolve();
    }

    /**
     * `workgroups_parent_id_agency_id_foreign` (a child workgroup) and
     * `deployments_workgroup_id_agency_id_foreign` (any deployment, closed rows
     * included) both RESTRICT, so a workgroup still in use is refused at the
     * database with 23001 rather than by a hand-checked guard here.
     *
     * NOT `workgroups_head_id_agency_id_foreign`: `workgroups.head_id` points *out of*
     * `workgroups` at an employee, so it restricts deleting the **employee**, not
     * deleting the workgroup — and an employee is soft-deleted (an UPDATE), so it
     * never fires at all. MEASURED: removing a workgroup that has a head returns
     * 302. The tree hides Remove where the two real RESTRICTs would bite
     * (`deployments_count` plus the child count), which is the primary
     * defence and stays; hiding an action is not translating a refusal
     * though, and this route is a live authorized one reachable from a stale
     * index page or by URL. There is no field to hang the message on, so it
     * arrives as a flash error naming what stands in the way.
     */
    public function destroy(Workgroup $workgroup): RedirectResponse
    {
        Gate::authorize('delete', $workgroup);

        try {
            // Its own transaction, so the refusal is recoverable and not just
            // caught — see update() for why.
            DB::transaction(fn () => $workgroup->delete());
        } catch (QueryException $e) {
            if ($e->getCode() !== '23001') {
                throw $e;
            }

            return redirect()->route('workgroups.index')
                ->with('error', "{$workgroup->name} cannot be removed while a workgroup sits under it or anyone has ever been deployed to it.");
        }

        return redirect()->route('workgroups.index')->with('success', "{$workgroup->name} removed.");
    }
}

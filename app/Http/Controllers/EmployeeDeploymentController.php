<?php

namespace App\Http\Controllers;

use App\Actions\ReassignEmployee;
use App\Actions\TransferEmployee;
use App\Http\Requests\DeployEmployeeRequest;
use App\Http\Requests\EndEmployeeDeploymentRequest;
use App\Jobs\FanOutRecompute;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Transfer to a new placement, reassign elsewhere while the placement
 * stays open, or end the open placement; deployment history is read on the
 * employee profile.
 */
class EmployeeDeploymentController extends Controller
{
    /**
     * Deploy the employee: a transfer, or a reassignment if the request says
     * so. One endpoint and two actions (decision 35) — `reassignment` is the
     * whole discriminator, exactly as `parent_id IS NOT NULL` is the whole
     * fact in the table. The parent is never in the payload; ReassignEmployee
     * resolves it from the employee's own open placement.
     *
     * The branch lives here rather than inside one action with a flag,
     * because the two differ in precisely one thing — whether the open
     * placement is closed — and that write is what decision 30 turned into
     * access control. Which calls perform it should be readable from the
     * class name, not from an argument.
     *
     * workgroup_id names a real row of this tenant (DeployEmployeeRequest's
     * exists rule), so Workgroup::findOrFail is safe: AgencyScope already
     * limits it to the current tenant, and the request already proved the id
     * exists there before this method runs.
     */
    public function store(
        DeployEmployeeRequest $request,
        Employee $employee,
        TransferEmployee $transfer,
        ReassignEmployee $reassign,
    ): RedirectResponse {
        $workgroup = Workgroup::findOrFail($request->validated('workgroup_id'));
        $reassigning = $request->boolean('reassignment');

        try {
            $reassigning
                ? $reassign->handle($employee, $workgroup, $request->date('starts'), $request->date('ends'))
                : $transfer->handle($employee, $workgroup, $request->date('starts'), $request->date('ends'));
        } catch (QueryException $e) {
            /**
             * The database decides all of these, and this catch only
             * translates the refusal it already made — neither action runs a
             * pre-check (R16) precisely so a concurrent request can never
             * slip between a check and the insert. Do not "simplify" any
             * branch into a query that looks for the conflict before
             * writing: that would reintroduce the second source of truth R16
             * forbids, and it still wouldn't be safe under concurrency.
             *
             * | SQLSTATE | Constraint | What reaches it |
             * | -------- | ---------- | --------------- |
             * | 23P01 | deployments_no_overlap | a transfer whose new range covers a closed, historical deployment — or, single-threaded impossible but the reason this branch exists, a second concurrent transfer that opened its own row between this one's close and insert |
             * | 23P01 | deployments_no_overlapping_movements | a reassignment overlapping one already recorded; nobody is detailed to two places at once |
             * | 23514 | deployments_dates_ordered | `starts` is on or before the open placement's own `starts`, so closing it at `starts - 1` would leave `ends < starts` |
             * | P0001 | deployments_nested | a reassignment reaching outside the placement it departs from, or a transfer closing a placement while a reassignment nested in it reaches past the new end date |
             *
             * MEASURED: with an open deployment present, no `starts` value
             * can reach 23P01 single-threaded on the transfer path — the
             * exclusion constraint keeps every range disjoint, so the open
             * row always holds the maximum start and a colliding date trips
             * 23514 first. 23P01 is the concurrency case and the
             * closed-history case; 23514 is the one a clerk backdating a
             * transfer actually hits.
             *
             * P0001 is mostly pre-empted by DeployEmployeeRequest, which
             * mirrors the containment rule as a field error, but not
             * entirely: the transfer limb (a nested reassignment stranded by
             * an early close) has no field to hang on, and a concurrent
             * reassignment can appear after validation runs. So it is
             * translated rather than left to surface as a 500, and the
             * message names the sequence that fixes it.
             *
             * Anything that is none of these is a real failure, not a normal
             * user mistake, so it re-throws untouched.
             */
            throw match ($e->getCode()) {
                '23P01' => ValidationException::withMessages(['starts' => [
                    $reassigning ? 'Overlaps an existing reassignment.' : 'Overlaps an existing deployment.',
                ]]),
                '23514' => ValidationException::withMessages(['starts' => ['Before the current placement began.']]),
                'P0001' => ValidationException::withMessages(['starts' => [
                    $this->frozen($e)
                        ? 'That range covers a locked month. Unlock the ledger first.'
                        : ($reassigning
                            ? 'Outside the placement this reassignment departs from.'
                            : 'End the open reassignment before transferring this employee.'),
                ]]),
                default => $e,
            };
        }

        $this->recompute($employee, $request->date('starts'), $request->date('ends'));

        $verb = $reassigning ? 'reassigned to' : 'moved to';

        return redirect()->route('employees.show', $employee)->with('success', "{$employee->name} {$verb} {$workgroup->name}.");
    }

    /**
     * End the placement on its last day, inclusive. Unlike a transfer, this
     * opens no replacement row, so ends is the supplied day, not day - 1.
     *
     * **An expected-value predicate, and it is a security control rather than
     * tidiness.** This used to close "the open placement of this employee",
     * which the 2026-09-10 adversarial review found could close the wrong
     * row: a form rendered before a concurrent transfer and submitted after
     * it closed whatever was open *by then* — the replacement placement, in a
     * workgroup the clerk never saw. Decision 30 makes deployment ranges
     * access control, so that is a visibility change nobody asked for, not a
     * data-quality slip.
     *
     * `WHERE id = :deployment AND ends IS NOT DISTINCT FROM :expects` closes
     * both halves: the id pins which row, and `expects` — the `ends` the form
     * was rendered with — pins that the row has not changed since. `IS NOT
     * DISTINCT FROM` and not `=` because the expected value is normally null,
     * and `ends = NULL` is never true.
     *
     * It stays a conditional UPDATE rather than a read followed by save():
     * re-dating a deployment violates no constraint, so the predicate is the
     * only guard, and a read-then-write leaves exactly the window this
     * closes. Eloquent supplies updated_at on a query-builder update.
     *
     * Zero affected rows now means "not the row you were looking at any
     * more" — someone else moved, ended or corrected it — which is reported
     * rather than retried, because the clerk needs to see the current state
     * before choosing a date again.
     *
     * `whereNull('parent_id')` is kept by the request's own `exists` rule:
     * since decision 31 a reassigned employee has two rows covering today,
     * and ending a placement must never silently end the reassignment nested
     * in it.
     *
     * | SQLSTATE | Constraint | Reached by |
     * | --- | --- | --- |
     * | 23514 | deployments_dates_ordered | an `ends` before the row's own `starts`; the request checks first, but a concurrent correction can re-date the row between the two |
     * | P0001 | deployments_nested | closing the placement while a reassignment nested in it reaches past that day — end the reassignment first |
     *
     * The transaction makes a caught refusal recoverable even inside another
     * transaction. Other failures propagate unchanged.
     */
    public function update(EndEmployeeDeploymentRequest $request, Employee $employee): RedirectResponse
    {
        try {
            $closed = DB::transaction(fn () => $employee->deployments()
                ->whereKey($request->validated('deployment'))
                ->whereRaw('ends IS NOT DISTINCT FROM ?', [$request->date('expects')?->toDateString()])
                ->update(['ends' => $request->date('ends')]));
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                '23514' => ValidationException::withMessages(['ends' => ['Before the current placement began.']]),
                'P0001' => ValidationException::withMessages(['ends' => [
                    $this->frozen($e)
                        ? 'That placement covers a locked month. Unlock the ledger first.'
                        : 'End the reassignment nested under this placement first.',
                ]]),
                default => $e,
            };
        }

        if ($closed === 0) {
            return redirect()->route('employees.show', $employee)
                ->with('error', 'That placement changed while you were looking at it. Check the history and try again.');
        }

        // From the earlier of the two end dates: moving `ends` in orphans
        // every day after it, and moving it out employs them.
        $expects = $request->date('expects');
        $ends = $request->date('ends');

        $this->recompute($employee, $expects !== null && $expects->lt($ends) ? $expects : $ends, null);

        return redirect()->route('employees.show', $employee)->with('success', 'Placement ended.');
    }

    /**
     * Correct a wrongly recorded deployment by removing it; the right one is
     * then created afresh (decision 35). There is deliberately no PATCH: the
     * row was never true, so there is nothing to preserve, and principle 2's
     * "a change is a new range" does not apply to a mistake.
     *
     * A hard delete, and `Deployment` carries no SoftDeletes precisely so
     * that this is one. A soft delete is an UPDATE, so the row would keep its
     * range, go on occupying the timeline the exclusion constraints index,
     * and refuse its own replacement with 23P01.
     *
     * | SQLSTATE | Constraint | Reached by |
     * | --- | --- | --- |
     * | 23001 | deployments_parent_id_employee_id_foreign | deleting a placement that still has a reassignment nested under it — RESTRICT is stated explicitly on that FK for this reason |
     *
     * Gated by EmployeePolicy::update, the same ability every other write on
     * this employee's placements uses; the route's ->scopeBindings() has
     * already made a deployment of another employee a 404.
     *
     * No longer unconditional, and the two rules that changed it both landed
     * in Milestone 6. `deployments_frozen_month` (decision 55, open item 5)
     * refuses any write whose range covers a locked ledger month, so a
     * deletion reaching a signed month raises P0001 — translated below.
     * And decision 82 made a deployment range decide which days exist at
     * all, so removing one leaves workdays behind: the recompute is what
     * clears them (decision 86).
     */
    public function destroy(Employee $employee, Deployment $deployment): RedirectResponse
    {
        Gate::authorize('update', $employee);

        try {
            // Wrapped for the same reason update() is: a refusal aborts the
            // transaction it runs in, so without a savepoint of its own the
            // caught 23001 would leave the surrounding transaction poisoned
            // (25P02) and every later statement in it would fail.
            DB::transaction(fn () => $deployment->delete());
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                '23001' => ValidationException::withMessages([
                    'deployment' => ['Remove the reassignment nested under this placement first.'],
                ]),
                'P0001' => ValidationException::withMessages([
                    'deployment' => [
                        $this->frozen($e)
                            ? 'That placement covers a locked month. Unlock the ledger first.'
                            : 'Remove the reassignment nested under this placement first.',
                    ],
                ]),
                default => $e,
            };
        }

        $this->recompute($employee, $deployment->starts, $deployment->ends);

        return redirect()->route('employees.show', $employee)->with('success', 'Deployment removed.');
    }

    /**
     * Which P0001 this is.
     *
     * Two triggers on this table raise it and they ask for opposite
     * remedies: `deployments_nested` says end the reassignment, and
     * `deployments_frozen_month` (decision 55) says unlock the ledger. A
     * `match` on the SQLSTATE alone told a clerk whose September was signed
     * to go and look for a reassignment that does not exist. Postgres offers
     * no constraint name on a RAISE — plpgsql's exception carries only the
     * message — so the message is the only thing there is to read, and it is
     * the trigger's own words rather than a phrase invented here.
     */
    private function frozen(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'locked months');
    }

    /**
     * A deployment change is a recompute event (Workday rule 3, decision 86).
     *
     * Not because a placement holds a figure — it holds none — but because
     * decision 82 made the deployment range the answer to *which days exist*,
     * and because a workgroup is what a suspension is declared against, so
     * moving somebody moves which office closures reach them. Narrowing a
     * range is the sharp case: the days it no longer covers keep the workdays
     * they were given, and `Computer` deletes them on the way through.
     *
     * `$to` null is open-ended, which is what an open placement is. The job
     * clamps both ends to the days actually computed.
     */
    private function recompute(Employee $employee, CarbonInterface $from, ?CarbonInterface $to): void
    {
        FanOutRecompute::forEmployees([$employee->id], $from->toDateString(), $to?->toDateString());
    }
}

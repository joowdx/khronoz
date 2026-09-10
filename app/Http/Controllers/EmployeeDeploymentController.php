<?php

namespace App\Http\Controllers;

use App\Actions\ReassignEmployee;
use App\Actions\TransferEmployee;
use App\Http\Requests\DeployEmployeeRequest;
use App\Http\Requests\EndEmployeeDeploymentRequest;
use App\Models\Employee;
use App\Models\Workgroup;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
                    $reassigning
                        ? 'Outside the placement this reassignment departs from.'
                        : 'End the open reassignment before transferring this employee.',
                ]]),
                default => $e,
            };
        }

        $verb = $reassigning ? 'reassigned to' : 'moved to';

        return redirect()->route('employees.show', $employee)->with('success', "{$employee->name} {$verb} {$workgroup->name}.");
    }

    /**
     * End the placement on its last day, inclusive. Unlike a move, this
     * opens no replacement row, so ends is the supplied day, not day - 1.
     *
     * `whereNull('parent_id')` narrows this to the *substantive* row: since
     * decision 31 a reassigned employee has two open deployments, and without
     * it this one statement would end the open reassignment as well as the
     * placement — silently, since both match "the open one for this employee".
     *
     * The open-row guard is part of the UPDATE, never a model read followed
     * by save(): re-dating a closed deployment violates no constraint, so
     * the application is the only guard against a stale close rewriting
     * history. Zero affected rows means there was nothing left to end.
     * Eloquent supplies updated_at on this query-builder update.
     *
     * | SQLSTATE | Constraint | Reached by |
     * | --- | --- | --- |
     * | 23514 | deployments_dates_ordered | a placement that starts after ends; the request checks first, but a concurrent move can change the open placement before this write |
     *
     * The transaction makes a caught refusal recoverable even inside another
     * transaction. Other failures propagate unchanged.
     */
    public function update(EndEmployeeDeploymentRequest $request, Employee $employee): RedirectResponse
    {
        try {
            $closed = DB::transaction(fn () => $employee->deployments()->whereNull('parent_id')->whereNull('ends')
                ->update(['ends' => $request->date('ends')]));
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                '23514' => ValidationException::withMessages(['ends' => ['Before the current placement began.']]),
                default => $e,
            };
        }

        if ($closed === 0) {
            return redirect()->route('employees.show', $employee)->with('error', 'No open placement to end.');
        }

        return redirect()->route('employees.show', $employee)->with('success', 'Placement ended.');
    }
}

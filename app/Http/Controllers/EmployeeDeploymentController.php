<?php

namespace App\Http\Controllers;

use App\Actions\MoveEmployee;
use App\Http\Requests\MoveEmployeeRequest;
use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Move an employee to a new unit. The only write this controller makes is
 * through MoveEmployee (R16): close the currently open deployment, then open
 * the new one, in one transaction, with no overlap pre-check — see that
 * action's own docblock for why. There is no index/show/update/destroy here:
 * deployment history is read through EmployeeController::show, and a
 * deployment row is never edited or removed once written, only superseded by
 * the next move.
 */
class EmployeeDeploymentController extends Controller
{
    /**
     * unit_id names a real row of this tenant (MoveEmployeeRequest's exists
     * rule), so Unit::findOrFail is safe: AgencyScope already limits it to
     * the current tenant, and the request already proved the id exists
     * there before this method runs.
     */
    public function store(MoveEmployeeRequest $request, Employee $employee, MoveEmployee $move): RedirectResponse
    {
        $unit = Unit::findOrFail($request->validated('unit_id'));

        try {
            $move->handle($employee, $unit, $request->date('starts'));
        } catch (QueryException $e) {
            /**
             * The database decides both of these, and this catch only
             * translates the refusal it already made — MoveEmployee runs no
             * pre-check (R16) precisely so a concurrent request can never
             * slip between a check and the insert. Do not "simplify" either
             * branch into a query that looks for the conflict before
             * writing: that would reintroduce the second source of truth R16
             * forbids, and it still wouldn't be safe under concurrency.
             *
             * | SQLSTATE | Constraint                | The move that reaches it |
             * | -------- | ------------------------- | ------------------------ |
             * | 23P01    | deployments_no_overlap    | the new range covers a closed, historical deployment — or, single-threaded impossible but the reason this branch exists, a second concurrent move that opened its own row between this one's close and insert |
             * | 23514    | deployments_dates_ordered | `starts` is on or before the open deployment's own `starts`, so closing it at `starts - 1` would leave `ends < starts` |
             *
             * MEASURED: with an open deployment present, no `starts` value
             * can reach 23P01 single-threaded — the exclusion constraint
             * keeps every range disjoint, so the open row always holds the
             * maximum start and a colliding date trips 23514 first. 23P01 is
             * the concurrency case and the closed-history case; 23514 is the
             * one a clerk backdating a move actually hits.
             *
             * Anything that is neither is a real failure, not a normal user
             * mistake, so it re-throws untouched.
             */
            throw match ($e->getCode()) {
                '23P01' => ValidationException::withMessages(['starts' => ['Overlaps an existing deployment.']]),
                '23514' => ValidationException::withMessages(['starts' => ['Before the current placement began.']]),
                default => $e,
            };
        }

        return redirect()->route('employees.show', $employee)->with('success', "{$employee->name} moved to {$unit->name}.");
    }
}

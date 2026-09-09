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
             * deployments_no_overlap (EXCLUDE USING gist, SQLSTATE 23P01) is
             * still the only thing deciding whether this move collides with
             * an existing deployment — MoveEmployee runs no pre-check (R16)
             * precisely so a concurrent request can never slip between a
             * check and the insert. This catch does not decide anything
             * itself; it only translates the constraint's own refusal into a
             * message this caller can act on. Do not "simplify" this into a
             * pre-check that queries for an overlap before writing — that
             * would reintroduce the second source of truth R16 forbids, and
             * it still wouldn't be safe under concurrency. Any other
             * SQLSTATE is a real failure, not a normal user mistake, so it
             * re-throws untouched.
             */
            if ($e->getCode() !== '23P01') {
                throw $e;
            }

            throw ValidationException::withMessages(['starts' => ['Overlaps an existing deployment.']]);
        }

        return redirect()->route('employees.show', $employee)->with('success', "{$employee->name} moved to {$unit->name}.");
    }
}

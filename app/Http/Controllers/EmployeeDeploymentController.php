<?php

namespace App\Http\Controllers;

use App\Actions\MoveEmployee;
use App\Http\Requests\MoveEmployeeRequest;
use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;

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

        $move->handle($employee, $unit, $request->date('starts'));

        return redirect()->route('employees.show', $employee)->with('success', "{$employee->name} moved to {$unit->name}.");
    }
}

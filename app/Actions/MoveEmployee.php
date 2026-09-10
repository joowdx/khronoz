<?php

namespace App\Actions;

use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Unit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class MoveEmployee
{
    /**
     * Place $employee in $unit starting $starts: close the currently open
     * deployment, if any, the day before, then open the new one — in one
     * transaction, close first (R16).
     *
     * Deliberately no overlap pre-check. deployments_no_overlap (an EXCLUDE
     * USING gist constraint, and NOT deferrable precisely so it fires at
     * statement end rather than commit) is the last word on whether two
     * ranges collide; a hand-written check here would be a second source of
     * truth that can drift from it. So this method lets the INSERT below
     * fail with SQLSTATE 23P01 when it would overlap, rather than guessing
     * first — see MoveEmployeeTest for what that looks like from a direct
     * caller. EmployeeDeploymentController, the HTTP entry point, catches
     * that same QueryException and translates it into a ValidationException
     * on `starts` for its own caller; that is a translation of this
     * method's own refusal, not a second check, so it changes nothing here.
     *
     * A rehire is the same operation after a gap: with no open deployment,
     * nothing is closed and a new range preserves every previous placement.
     */
    public function handle(Employee $employee, Unit $unit, CarbonInterface $starts): Deployment
    {
        return DB::transaction(function () use ($employee, $unit, $starts): Deployment {
            $employee->currentDeployment?->update(['ends' => $starts->copy()->subDay()]);

            return $employee->deployments()->create([
                'agency_id' => $employee->agency_id,
                'unit_id' => $unit->id,
                'starts' => $starts,
                'ends' => null,
            ]);
        });
    }
}

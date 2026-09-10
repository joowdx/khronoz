<?php

namespace App\Actions;

use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class TransferEmployee
{
    /**
     * Place $employee in $workgroup starting $starts: close the currently open
     * substantive deployment, if any, the day before, then open the new one —
     * in one transaction, close first (R16).
     *
     * This is the verb for the plantilla item moving, which is why it closes
     * the previous row: the item left, so the placement ended (decision 35).
     * One action covers three arrangements that are the same operation —
     * an initial placement (nothing open to close), a transfer, and a rehire
     * after a gap (again nothing open, and every previous placement
     * preserved). A *reassignment*, where the person moves and the item stays,
     * closes nothing and is ReassignEmployee.
     *
     * $ends is optional and normally null, an open-ended placement. A
     * fixed-term appointment — contractual, casual, co-terminous — has a known
     * last day, and recording it here is what lets the exclusion constraint
     * accept the next placement without a separate closing write.
     *
     * Deliberately no overlap pre-check. deployments_no_overlap (an EXCLUDE
     * USING gist constraint, partial on `parent_id IS NULL`, and NOT
     * deferrable precisely so it fires at statement end rather than commit)
     * is the last word on whether two ranges collide; a hand-written check
     * here would be a second source of truth that can drift from it. So this
     * method lets the INSERT below fail with SQLSTATE 23P01 when it would
     * overlap, rather than guessing first — see TransferEmployeeTest for what
     * that looks like from a direct caller. EmployeeDeploymentController, the
     * HTTP entry point, catches that same QueryException and translates it
     * into a ValidationException on `starts` for its own caller; that is a
     * translation of this method's own refusal, not a second check, so it
     * changes nothing here.
     *
     * The same reasoning covers deployments_nested: closing the open
     * placement while a reassignment nested inside it reaches past the new
     * end date is refused with P0001, and the controller translates that too.
     * Ending the reassignment first is the correct sequence, and it is a
     * paper trail rather than an inconvenience.
     */
    public function handle(Employee $employee, Workgroup $workgroup, CarbonInterface $starts, ?CarbonInterface $ends = null): Deployment
    {
        return DB::transaction(function () use ($employee, $workgroup, $starts, $ends): Deployment {
            $employee->currentDeployment?->update(['ends' => $starts->copy()->subDay()]);

            return $employee->deployments()->create([
                'agency_id' => $employee->agency_id,
                'workgroup_id' => $workgroup->id,
                'starts' => $starts,
                'ends' => $ends,
            ]);
        });
    }
}

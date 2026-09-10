<?php

namespace App\Actions;

use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReassignEmployee
{
    /**
     * Send $employee to work in $workgroup from $starts while their
     * substantive placement stays open: a reassignment, or a detail — the
     * label varies by office and no rule reads it (decision 31).
     *
     * The mirror image of TransferEmployee. That verb closes the previous row
     * because the plantilla item moved; this one closes nothing, because the
     * item never left. That is the whole difference, and it is why these are
     * two actions rather than one with a flag: the row that gets closed is
     * the security-relevant write under decision 30, and which calls perform
     * it should be readable from the class name.
     *
     * The parent is resolved here and never passed in (decision 35). A
     * reassignment's parent is always the employee's own open substantive
     * placement, so a caller has no choice to make and offering one would
     * only let it name a row the rule does not permit.
     *
     * Deliberately no range pre-check, the same R16 reasoning
     * TransferEmployee documents: deployments_nested is the last word on
     * whether this range sits inside its parent's, and
     * deployments_no_overlapping_movements on whether it collides with
     * another reassignment. Both refuse at statement end and the controller
     * translates them.
     *
     * The one thing this action must check itself is the absence of a
     * parent, because no constraint can: with no open placement, parent_id
     * would be null and the database would accept the row as a perfectly
     * valid *substantive* placement. That is the only way a reassignment can
     * silently become a transfer, so it raises rather than writes.
     *
     * DeployEmployeeRequest checks the same thing first and turns it into a
     * field error, so this raise is reached only by a direct caller or by one
     * narrow race — a concurrent request ending the placement between
     * validation and this transaction. It is left as an exception rather than
     * translated, because after validation it is a broken invariant and not a
     * user mistake, and inventing an exception class to carry that one race
     * would be speculative.
     */
    public function handle(Employee $employee, Workgroup $workgroup, CarbonInterface $starts, ?CarbonInterface $ends = null): Deployment
    {
        return DB::transaction(function () use ($employee, $workgroup, $starts, $ends): Deployment {
            $placement = $employee->currentDeployment()->first();

            if ($placement === null) {
                throw new RuntimeException('Cannot reassign an employee with no open placement.');
            }

            return $employee->deployments()->create([
                'agency_id' => $employee->agency_id,
                'workgroup_id' => $workgroup->id,
                'parent_id' => $placement->id,
                'starts' => $starts,
                'ends' => $ends,
            ]);
        });
    }
}

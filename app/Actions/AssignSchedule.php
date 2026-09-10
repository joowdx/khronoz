<?php

namespace App\Actions;

use App\Models\Employee;
use App\Models\Roster;
use App\Models\Schedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class AssignSchedule
{
    /**
     * Put $employee on $schedule from $starts, with the cycle anchored at
     * $anchor: close the roster covering the day before, if any, then open the
     * new one — in one transaction, close first, the same shape
     * TransferEmployee uses on deployments.
     *
     * This is the ad-hoc single assignment, so `team_id` stays null. A roster
     * issued from a cohort carries the team it came from and is AssignTeam;
     * the difference is provenance and where the schedule and anchor come
     * from, not what gets written.
     *
     * `$anchor` is deliberately a separate argument and not derived from
     * `$starts`. They are different facts: `starts` is when this assignment
     * begins, `anchor` is cycle day 0, and a mid-cycle assignment needs them
     * to differ or the employee restarts the rotation instead of joining it
     * where it already is. Defaulting one to the other would silently
     * re-phase every mid-rotation hire.
     *
     * Deliberately no overlap pre-check (R16). rosters_no_overlap is the last
     * word on whether two ranges collide, and it is what makes a one-week
     * override a one-week roster — the constraint forces the standing roster
     * to be ended first, which is the right paper trail (04-scheduling.md
     * rule 3). A hand-written check here would be a second source of truth
     * that can drift from it and would still not be safe under concurrency.
     */
    public function handle(
        Employee $employee,
        Schedule $schedule,
        CarbonInterface $anchor,
        CarbonInterface $starts,
        ?CarbonInterface $ends = null,
    ): Roster {
        return DB::transaction(function () use ($employee, $schedule, $anchor, $starts, $ends): Roster {
            $employee->rosters()->covering($starts)->first()
                ?->update(['ends' => $starts->copy()->subDay()]);

            return $employee->rosters()->create([
                'agency_id' => $employee->agency_id,
                'schedule_id' => $schedule->id,
                'team_id' => null,
                'anchor' => $anchor,
                'starts' => $starts,
                'ends' => $ends,
            ]);
        });
    }
}

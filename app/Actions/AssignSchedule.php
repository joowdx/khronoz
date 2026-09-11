<?php

namespace App\Actions;

use App\Jobs\FanOutRecompute;
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
     * **The recompute is dispatched here and not at the caller**, which is
     * the one place this codebase departs from `ImportTimelogs`' rule that
     * dispatch belongs to the caller. That rule exists so a recompute failure
     * cannot retry an ingestion that already succeeded; nothing retries an
     * action inside a request. What there is instead of a caller is nothing:
     * no controller writes a roster yet, and leaving the event unwired until
     * the scheduling screens land would leave 06-attendance.md rule 3 stating
     * a guarantee no code keeps. `AssignSchedules` and `AssignTeam` call this
     * inside a transaction of their own, so a rollback can leave a queued
     * recompute behind — which reads unchanged rows and writes back what was
     * already there. A recompute is a repair, never a side effect: running it
     * when nothing changed costs time and changes nothing.
     *
     * The span opens at `$starts` and never closes, whatever `$ends` says:
     * closing the standing roster hands the days after `$ends` to no roster
     * at all, so they change too. `FanOutRecompute` clamps the far end to the
     * last day actually computed (decision 86).
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
        $roster = DB::transaction(function () use ($employee, $schedule, $anchor, $starts, $ends): Roster {
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

        FanOutRecompute::forEmployees([$employee->id], $starts->toDateString());

        return $roster;
    }
}

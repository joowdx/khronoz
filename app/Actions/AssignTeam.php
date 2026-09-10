<?php

namespace App\Actions;

use App\Models\Employee;
use App\Models\Roster;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AssignTeam
{
    /**
     * Roster $employees onto $team from $starts: one roster each, carrying
     * the team's id and **copies** of its schedule and anchor.
     *
     * Copied and not referenced, which is the whole design of `team_id`
     * (07-constraints.md): a roster's `schedule_id` and `anchor` may later
     * diverge from the team's and nothing forbids it, so an agency can slide
     * one nurse's anchor by a day without taking her off the cohort.
     * Resolution reads the roster and never the team, so the copy is the
     * operative value and the team is provenance.
     *
     * **Re-anchoring a team re-issues its members' rosters rather than
     * updating them in place**, and this action is that operation too: call it
     * again after changing the team's anchor, with `$starts` on the day the
     * new phase begins. AssignSchedule's close-then-open then ends each
     * member's current roster the day before and opens the new one, so what
     * the cohort was rostered to work last month stays answerable. Updating
     * `anchor` on the existing rows instead would retroactively re-phase
     * every workday already computed against them — the same reason a change
     * is a new range everywhere else in this schema.
     *
     * All or nothing, like AssignSchedules: a cohort half-assigned is worse
     * than one not assigned, because the half that succeeded looks
     * deliberate.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Roster>
     */
    public function handle(
        Collection $employees,
        Team $team,
        CarbonInterface $starts,
        ?CarbonInterface $ends = null,
    ): Collection {
        return DB::transaction(fn (): Collection => $employees->map(function ($employee) use ($team, $starts, $ends) {
            $roster = app(AssignSchedule::class)->handle(
                $employee, $team->schedule, $team->anchor, $starts, $ends
            );

            $roster->update(['team_id' => $team->id]);

            return $roster;
        }));
    }
}

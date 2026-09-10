<?php

namespace App\Actions;

use App\Models\Employee;
use App\Models\Roster;
use App\Models\Schedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AssignSchedules
{
    /**
     * The bulk form of AssignSchedule: one roster per employee in
     * $employees, all on the same schedule and anchor.
     *
     * A second action class rather than a `Collection|Employee` union on
     * AssignSchedule, because the two differ in a property that matters —
     * this one is **all or nothing**. Every roster is written in one
     * transaction, so a select-all over forty people that collides on the
     * thirty-ninth leaves none of them assigned rather than thirty-eight.
     * Partial success is the wrong answer for a bulk action a timekeeper will
     * retry after fixing the one conflict.
     *
     * $employees is built at the call site — a tag filter plus select-all is
     * the ad-hoc shape (01-organization.md rule 5: "a fact you filter by is a
     * tag"), and these rosters carry `team_id` null because a tag is not a
     * cohort. Assigning a rotation cohort is AssignTeam.
     *
     * The per-employee work is AssignSchedule's, called inside this
     * transaction rather than reimplemented: the close-then-open sequence and
     * its reasoning live in one place, and a nested DB::transaction is a
     * savepoint, so the outer rollback still takes everything with it.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, Roster>
     */
    public function handle(
        Collection $employees,
        Schedule $schedule,
        CarbonInterface $anchor,
        CarbonInterface $starts,
        ?CarbonInterface $ends = null,
    ): Collection {
        return DB::transaction(fn (): Collection => $employees->map(
            fn ($employee) => app(AssignSchedule::class)->handle($employee, $schedule, $anchor, $starts, $ends)
        ));
    }
}

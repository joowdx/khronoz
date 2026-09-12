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

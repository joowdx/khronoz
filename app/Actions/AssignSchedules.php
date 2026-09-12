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

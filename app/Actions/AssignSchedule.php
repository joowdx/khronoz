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

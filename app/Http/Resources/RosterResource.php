<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Roster` interface in resources/js/types/index.d.ts.
 *
 * @mixin Roster
 */
class RosterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded(
                'employee',
                fn (Employee $employee) => EmployeeResource::make($employee)->resolve(),
            ),
            'schedule_id' => $this->schedule_id,
            'schedule' => $this->whenLoaded(
                'schedule',
                fn (Schedule $schedule) => ScheduleResource::make($schedule)->resolve(),
            ),
            'team_id' => $this->team_id,
            'team' => $this->whenLoaded('team', fn (Team $team) => TeamResource::make($team)->resolve()),
            'anchor' => $this->anchor->toDateString(),
            'starts' => $this->starts->toDateString(),
            'ends' => $this->ends?->toDateString(),
        ];
    }
}

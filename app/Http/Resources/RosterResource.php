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
 * A roster is the only assignment: this employee follows this schedule from
 * `starts` to `ends`, with the cycle anchored at `anchor`. `ends` is nullable
 * and means "still running"; `rosters_no_overlap` guarantees at most one covers
 * any given date, so resolution is a lookup rather than a precedence rule.
 *
 * `employee` takes the closure form even though `employee_id` is NOT NULL:
 * `Employee` soft-deletes, so the relation loads null through its own global
 * scope once somebody is removed, and the one-argument `whenLoaded` inside
 * `make()` would then read `->id` on null (resources.md — it has bitten four
 * times). `team` is nullable outright: an ad-hoc assignment carries none.
 *
 * @mixin Roster
 */
class RosterResource extends JsonResource
{
    /** @return array<string, mixed> */
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

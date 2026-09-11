<?php

namespace App\Http\Resources;

use App\Models\Schedule;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Team` interface in resources/js/types/index.d.ts.
 *
 * A team is a named `(schedule, anchor)` cohort and holds no membership of its
 * own: the rosters carrying its `team_id` *are* its members (04-scheduling.md).
 * `people_count` is therefore a `withCount` on `rosters` and lives in the
 * consuming page's row interface, not on the shared one — resources.md.
 *
 * `anchor` is a date cast, so it goes out as `toDateString()`: app.timezone is
 * Asia/Manila and a bare date attribute serialises as a UTC instant, which on a
 * positive offset always names the day before.
 *
 * @mixin Team
 */
class TeamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'schedule_id' => $this->schedule_id,
            'schedule' => $this->whenLoaded(
                'schedule',
                fn (Schedule $schedule) => ScheduleResource::make($schedule)->resolve(),
            ),
            'anchor' => $this->anchor->toDateString(),
            'people_count' => $this->whenCounted('rosters'),
        ];
    }
}

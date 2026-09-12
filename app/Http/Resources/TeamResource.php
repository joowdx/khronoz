<?php

namespace App\Http\Resources;

use App\Models\Schedule;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Team` interface in resources/js/types/index.d.ts.
 *
 * @mixin Team
 */
class TeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
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

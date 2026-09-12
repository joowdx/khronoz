<?php

namespace App\Http\Resources;

use App\Models\Schedule;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Schedule` interface in resources/js/types/index.d.ts.
 *
 * `fallback_shift` is what a compressed week reverts to for the rest of an ISO
 * week when a holiday or suspension lands on one of its rest days
 * (04-scheduling.md rule 5). It is nullable, so it takes the closure form.
 *
 * @mixin Schedule
 */
class ScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'length' => $this->length,
            'fallback_shift_id' => $this->fallback_shift_id,
            'fallback_shift' => $this->whenLoaded(
                'fallbackShift',
                fn (Shift $shift) => ShiftResource::make($shift)->resolve(),
            ),
            'turns' => $this->whenLoaded('turns', fn () => TurnResource::collection($this->turns)->resolve()),
            'origin_id' => $this->origin_id,
            'origin' => $this->whenLoaded('origin', fn (Schedule $origin) => ScheduleResource::make($origin)->resolve()),
        ];
    }
}

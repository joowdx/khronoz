<?php

namespace App\Http\Resources;

use App\Models\Shift;
use App\Models\Turn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Turn` interface in resources/js/types/index.d.ts.
 *
 * A turn is one day of a cycle: `position` is 0-based and a schedule's turns
 * must occupy 0..length-1 exactly, which `turns_complete` enforces as a
 * deferred constraint trigger at COMMIT rather than per row.
 *
 * @mixin Turn
 */
class TurnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'shift_id' => $this->shift_id,
            'shift' => $this->whenLoaded('shift', fn (Shift $shift) => ShiftResource::make($shift)->resolve()),
        ];
    }
}

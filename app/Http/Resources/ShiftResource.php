<?php

namespace App\Http\Resources;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Shift` interface in resources/js/types/index.d.ts.
 *
 * `slots` is sent as the raw array the column holds — `[{in, out, grace,
 * window}]` with times as `"HH:MM"` that may run past 24:00, so `"30:00"` is
 * 06:00 the next day and the cap is 72:00 (04-scheduling.md). The front end
 * renders those strings and never reparses them into a Date; the arithmetic
 * that matters is the engine's, and `slots_valid()` is the last word on shape.
 *
 * `kind` is derived here rather than re-decided per row in TypeScript. A shift
 * with no slots is a rest day unless it is `remote`, which is the distinction
 * `shifts_remote_has_no_slots` and `shifts_off_credits_nothing` encode between
 * them, and every surface that draws a shift needs it: the ramp chip, the
 * hatch and the dashed box are three different marks (08-interface.md §5.23).
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $slots = $this->slots ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slots' => $slots,
            'required' => $this->required,
            'flex' => $this->flex,
            'remote' => $this->remote,
            'trust' => $this->trust,
            'color' => $this->color,
            'kind' => match (true) {
                $slots !== [] => 'working',
                (bool) $this->remote => 'remote',
                default => 'off',
            },
            'origin_id' => $this->origin_id,
            // `origin_id` is nullable and points at a platform-owned row, so
            // the closure form is required twice over — resources.md.
            'origin' => $this->whenLoaded('origin', fn (Shift $origin) => ShiftResource::make($origin)->resolve()),
        ];
    }
}

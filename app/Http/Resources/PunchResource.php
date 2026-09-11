<?php

namespace App\Http\Resources;

use App\Models\Punch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Punch` interface in resources/js/types/index.d.ts.
 *
 * One transit of a workday. `actual_at` null is a missed (or still-due)
 * punch, never an engine-invented time (decision 64); `expected_at` null is
 * a tap on a day that expected nothing — a rest day, a non-working holiday
 * or a suspension worked through (decision 78) — and `deviation` is null
 * with either. `kind` crosses as `{value, label}` from PunchKind.
 *
 * @mixin Punch
 */
class PunchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slot' => $this->slot,
            'kind' => ['value' => $this->kind->value, 'label' => $this->kind->label()],
            'expected_at' => $this->expected_at?->toDateTimeString(),
            'actual_at' => $this->actual_at?->toDateTimeString(),
            'deviation' => $this->deviation,
            'timelog_id' => $this->timelog_id,
        ];
    }
}

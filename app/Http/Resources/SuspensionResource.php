<?php

namespace App\Http\Resources;

use App\Models\Suspension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Suspension` interface in resources/js/types/index.d.ts.
 *
 * `workgroup` null is **agency-wide**, not missing, and it is the commonest
 * shape — a typhoon closes the office, not one division — so the page states
 * it in words rather than dashing it.
 *
 * `starts`/`ends` null together is the whole day. The pair is held by
 * `suspensions_hours_paired`, so a reader can rely on "one is set" meaning
 * both are.
 *
 * @mixin Suspension
 */
class SuspensionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workgroup_id' => $this->workgroup_id,
            'workgroup' => $this->whenLoaded(
                'workgroup',
                fn () => $this->workgroup === null ? null : WorkgroupResource::make($this->workgroup)->resolve(),
            ),
            'date' => $this->date->toDateString(),
            // `H:i:s` strings or null — never Carbon, never reparsed.
            'starts' => $this->starts,
            'ends' => $this->ends,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'declared_at' => $this->declared_at->toDateTimeString(),
            'user' => $this->whenLoaded(
                'user',
                fn () => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name],
            ),
        ];
    }
}

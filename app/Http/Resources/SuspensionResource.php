<?php

namespace App\Http\Resources;

use App\Models\Suspension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Suspension` interface in resources/js/types/index.d.ts.
 *
 * @mixin Suspension
 */
class SuspensionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
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

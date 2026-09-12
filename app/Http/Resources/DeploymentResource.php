<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Deployment` interface in resources/js/types/index.d.ts.
 *
 * @mixin \App\Models\Deployment
 */
class DeploymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workgroup' => $this->whenLoaded('workgroup', fn ($workgroup) => WorkgroupResource::make($workgroup)->resolve()),
            'parent_id' => $this->parent_id,
            'starts' => $this->starts?->toDateString(),
            'ends' => $this->ends?->toDateString(),
        ];
    }
}

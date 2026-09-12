<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Workgroup` interface in resources/js/types/index.d.ts.
 *
 * @mixin \App\Models\Workgroup
 */
class WorkgroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'kind' => $this->kind,
            'code' => $this->code,
            'name' => $this->name,
            'head_id' => $this->head_id,
            'head' => $this->whenLoaded('head', fn ($head) => EmployeeResource::make($head)->resolve()),
            'people_count' => $this->whenCounted('people'),
            'deployments_count' => $this->whenCounted('deployments'),
        ];
    }
}

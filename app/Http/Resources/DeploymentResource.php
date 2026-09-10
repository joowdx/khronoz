<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Deployment` interface in resources/js/types/index.d.ts (Task
 * 6). Carries no `employee`: every place this resource appears, it is
 * already nested under that employee (EmployeeResource's `current_deployment`
 * and `deployments`), so re-including it would only invite a cycle.
 *
 * `starts`/`ends` are `date`-cast columns, sent as plain `YYYY-MM-DD` strings
 * (->toDateString()) for the same reason as EmployeeResource's `birthdate` —
 * see that class's docblock.
 *
 * @mixin \App\Models\Deployment
 */
class DeploymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workgroup' => $this->whenLoaded('workgroup', fn ($workgroup) => WorkgroupResource::make($workgroup)->resolve()),
            // Sent as the id and not a boolean, even though only its
            // presence is ever read: the deployment history table needs to
            // indent a reassignment under the placement it departs from, and
            // that needs the parent's identity, not just the fact of one.
            'parent_id' => $this->parent_id,
            'starts' => $this->starts?->toDateString(),
            'ends' => $this->ends?->toDateString(),
        ];
    }
}

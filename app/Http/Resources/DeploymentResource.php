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
 * (->toDateString()) for the same reason as EmployeeResource's `hired_at` —
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
            'unit' => $this->whenLoaded('unit', fn ($unit) => UnitResource::make($unit)->resolve()),
            'starts' => $this->starts?->toDateString(),
            'ends' => $this->ends?->toDateString(),
        ];
    }
}

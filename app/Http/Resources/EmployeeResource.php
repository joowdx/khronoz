<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Employee` interface in resources/js/types/index.d.ts.
 *
 * @mixin \App\Models\Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'sex' => $this->sex === null ? null : ['value' => $this->sex->value, 'label' => $this->sex->label()],
            'birthdate' => $this->birthdate?->toDateString(),
            'email' => $this->email,
            'mobile' => $this->mobile,
            'position' => $this->position,
            'tags' => $this->tags,
            'exempt' => $this->exempt,
            'cadence_id' => $this->cadence_id,
            'current_deployment' => $this->whenLoaded('currentDeployment', fn ($deployment) => DeploymentResource::make($deployment)->resolve()),
            'deployments' => $this->whenLoaded('deployments', fn ($deployments) => DeploymentResource::collection($deployments)->resolve()),
        ];
    }
}

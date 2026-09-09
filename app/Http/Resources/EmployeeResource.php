<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Employee` interface in resources/js/types/index.d.ts (Task 6).
 *
 * `current_deployment` needs `currentDeployment.unit` eager-loaded by the
 * controller — EmployeeController::index and ::show both do, deliberately
 * (see their own docblocks): Model::shouldBeStrict() only arms the
 * lazy-loading guard on a hydrated collection of more than one row, so a
 * single-employee test cannot catch a missing eager load here, only a list
 * of two or more can. `deployments` (the full history, plural) is present
 * only on ::show, which is why it uses whenLoaded and can come back absent.
 *
 * @mixin \App\Models\Employee
 */
class EmployeeResource extends JsonResource
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
            'number' => $this->number,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'sex' => $this->sex?->value,
            'birthdate' => $this->birthdate,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'position' => $this->position,
            'tags' => $this->tags,
            'exempt' => $this->exempt,
            'hired_at' => $this->hired_at,
            'separated_at' => $this->separated_at,
            'current_deployment' => $this->whenLoaded('currentDeployment', fn ($deployment) => DeploymentResource::make($deployment)->resolve()),
            'deployments' => DeploymentResource::collection($this->whenLoaded('deployments')),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Employee` interface in resources/js/types/index.d.ts (Task 6).
 *
 * `current_deployment` needs `currentDeployment.workgroup` eager-loaded by the
 * controller — EmployeeController::index and ::show both do, deliberately
 * (see their own docblocks): Model::shouldBeStrict() only arms the
 * lazy-loading guard on a hydrated collection of more than one row, so a
 * single-employee test cannot catch a missing eager load here, only a list
 * of two or more can. `deployments` (the full history, plural) is present
 * only on ::show, which is why it uses whenLoaded and can come back absent.
 *
 * `birthdate` is a `date`-cast column, sent as
 * plain `YYYY-MM-DD` strings (->toDateString()), not the Carbon instance
 * itself: app.timezone is Asia/Manila, and a bare date-cast attribute
 * JSON-serializes as a UTC instant, which for a positive UTC offset always
 * lands on the day before (e.g. 2020-01-01 becomes
 * "2019-12-31T16:00:00.000000Z"). DeploymentResource's `starts`/`ends` carry
 * the same fix for the same reason.
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
            'sex' => $this->sex === null ? null : ['value' => $this->sex->value, 'label' => $this->sex->label()],
            'birthdate' => $this->birthdate?->toDateString(),
            'email' => $this->email,
            'mobile' => $this->mobile,
            'position' => $this->position,
            'tags' => $this->tags,
            'exempt' => $this->exempt,
            'current_deployment' => $this->whenLoaded('currentDeployment', fn ($deployment) => DeploymentResource::make($deployment)->resolve()),
            'deployments' => $this->whenLoaded('deployments', fn ($deployments) => DeploymentResource::collection($deployments)->resolve()),
        ];
    }
}

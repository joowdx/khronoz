<?php

namespace App\Http\Resources;

use App\Models\Exemption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Exemption` interface in resources/js/types/index.d.ts.
 *
 * @mixin Exemption
 */
class ExemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded(
                'employee',
                fn () => $this->employee === null ? null : EmployeeResource::make($this->employee)->resolve(),
            ),
            'date' => $this->date->toDateString(),
            'until' => $this->until->toDateString(),
            // `{value, label}`, the shape UserResource's permission groups
            // already use: the words come from the enum's own label(), never
            // from a map in TypeScript.
            'type' => ['value' => $this->type->value, 'label' => $this->type->label()],
            'starts' => $this->starts,
            'ends' => $this->ends,
            'reference' => $this->reference,
            'remarks' => $this->remarks,
            'approved_at' => $this->approved_at->toDateTimeString(),
            'spans_days' => $this->until->greaterThan($this->date),
        ];
    }
}

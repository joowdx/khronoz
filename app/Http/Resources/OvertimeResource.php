<?php

namespace App\Http\Resources;

use App\Models\Overtime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Overtime` interface in resources/js/types/index.d.ts.
 *
 * @mixin Overtime
 */
class OvertimeResource extends JsonResource
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
            'starts' => $this->starts->toDateTimeString(),
            'ends' => $this->ends->toDateTimeString(),
            'purpose' => $this->purpose,
            // `{value, label}`, as ExemptionResource and UserResource's
            // permission groups do — the words come from the enum.
            'mode' => ['value' => $this->mode->value, 'label' => $this->mode->label()],
            'reference' => $this->reference,
            // True when the stretch crosses midnight, which the list has to
            // show explicitly — otherwise "22:00–02:00" reads as running
            // backwards.
            'overnight' => $this->ends->toDateString() !== $this->starts->toDateString(),
        ];
    }
}

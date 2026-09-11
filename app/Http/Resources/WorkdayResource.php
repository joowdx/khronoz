<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Workday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Matches the `Workday` interface in resources/js/types/index.d.ts.
 *
 * `shift_name` is the frozen snapshot (`$workday->shift['shift']['name']`),
 * never the live `shifts` row — Workday rule 2. `resolvedShift()` is
 * provenance and this resource must not load it. `date` is a date-cast
 * column, sent as `YYYY-MM-DD` (.ai/rules/resources.md).
 *
 * `exemption` is the stamp on the day, not ExemptionResource: id, type as a
 * Choice, and reference. A personal slip still prints (daily rule 7).
 * `exemption_id` is nullable, so this uses the closure form of `whenLoaded`.
 *
 * @mixin Workday
 */
class WorkdayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded(
                'employee',
                fn (Employee $employee) => EmployeeResource::make($employee)->resolve(),
            ),
            'date' => $this->date->toDateString(),
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'premium' => $this->premium === null
                ? null
                : ['value' => $this->premium->value, 'label' => $this->premium->label()],
            'worked' => $this->worked,
            'credited' => $this->credited,
            'tardy' => $this->tardy,
            'undertime' => $this->undertime,
            'excess' => $this->excess,
            'night' => $this->night,
            'night_excess' => $this->night_excess,
            'punches' => $this->whenLoaded(
                'punches',
                fn (Collection $punches) => PunchResource::collection($punches)->resolve(),
            ),
            'exemption' => $this->whenLoaded(
                'exemption',
                fn (Exemption $exemption) => [
                    'id' => $exemption->id,
                    'type' => ['value' => $exemption->type->value, 'label' => $exemption->type->label()],
                    'reference' => $exemption->reference,
                ],
            ),
            'shift_name' => $this->shift['shift']['name'] ?? null,
            'computed_at' => $this->computed_at->toDateTimeString(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Enums\HolidayType;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Workday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Matches the `Workday` interface in resources/js/types/index.d.ts.
 *
 * @mixin Workday
 */
class WorkdayResource extends JsonResource
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
            'overtime' => $this->whenHas('overtime'),
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
            'holidays' => collect($this->shift['holidays'] ?? [])
                ->map(function (array $holiday): array {
                    $type = HolidayType::from($holiday['type']);

                    return [
                        'id' => $holiday['id'],
                        'name' => $holiday['name'],
                        'type' => ['value' => $type->value, 'label' => $type->label()],
                    ];
                })
                ->values()
                ->all(),
            'shift_name' => $this->shift['shift']['name'] ?? null,
            'computed_at' => $this->computed_at->toDateTimeString(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Workday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Matches the `Workday` interface in resources/js/types/index.d.ts.
 *
 * `shift_name` is the frozen snapshot (`$workday->shift['name']`), never the
 * live `shifts` row — Workday rule 2. `resolvedShift()` is provenance and
 * this resource must not load it. `date` is a date-cast column, sent as
 * `YYYY-MM-DD` (.ai/rules/resources.md).
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
            'shift_name' => ($this->shift ?? [])['name'] ?? null,
            'computed_at' => $this->computed_at->toDateTimeString(),
        ];
    }
}

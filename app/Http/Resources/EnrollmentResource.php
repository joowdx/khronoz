<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Enrollment` interface in resources/js/types/index.d.ts.
 *
 * `uid` crosses the wire as a string and is never reparsed (decision 42);
 * `starts`/`ends` as `YYYY-MM-DD` strings, never Carbon instances
 * (.ai/rules/resources.md — a bare date attribute serialises as a UTC instant,
 * which under Asia/Manila always names the day before).
 *
 * @mixin Enrollment
 */
class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn (Employee $employee) => EmployeeResource::make($employee)->resolve()),
            'terminal_id' => $this->terminal_id,
            'uid' => $this->uid,
            'privilege' => ['value' => $this->privilege->value, 'label' => $this->privilege->label()],
            'starts' => $this->starts->toDateString(),
            'ends' => $this->ends?->toDateString(),
            'current' => $this->starts->lessThanOrEqualTo(today())
                && ($this->ends === null || $this->ends->greaterThanOrEqualTo(today())),
        ];
    }
}

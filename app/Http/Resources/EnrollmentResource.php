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
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            // `employee_id` is NOT NULL, so `.ai/rules/resources.md`'s rule of
            // thumb — "if the migration writes ->nullable(), the resource
            // needs the closure" — said this one was safe. It is not.
            // `Employee` soft-deletes, so `with('employee')` on an offboarded
            // person loads **null** through the model's own scope, and the
            // one-argument `whenLoaded` hands that null straight to
            // `make()`: the framework only guards null when a closure is
            // passed (ConditionallyLoadsAttributes::whenLoaded, the check
            // after the func_num_args early return).
            //
            // The whole roster 500'd, every still-employed row with it — and
            // soft-deleting an enrolled employee is the ordinary offboarding
            // path.
            'employee' => $this->whenLoaded('employee', fn (Employee $employee) => EmployeeResource::make($employee)->resolve()),
            'terminal_id' => $this->terminal_id,
            'uid' => $this->uid,
            'privilege' => ['value' => $this->privilege->value, 'label' => $this->privilege->label()],
            'starts' => $this->starts->toDateString(),
            'ends' => $this->ends?->toDateString(),
            // Whether punches resolve through this row today. The date range
            // is the fact; this is the reading of it the list needs, computed
            // once here rather than re-derived in the page from two strings.
            'current' => $this->starts->lessThanOrEqualTo(today())
                && ($this->ends === null || $this->ends->greaterThanOrEqualTo(today())),
        ];
    }
}

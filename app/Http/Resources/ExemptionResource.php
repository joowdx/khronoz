<?php

namespace App\Http\Resources;

use App\Models\Exemption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Exemption` interface in resources/js/types/index.d.ts.
 *
 * `until` is the **last day, inclusive** and is NOT NULL (decision 38): a
 * one-day exemption carries `until = date`. It was nullable once, meaning "one
 * day", and that was a trap — `daterange(date, until, '[]')` with a null upper
 * bound is unbounded above, so a two-hour pass slip would have excused every
 * day thereafter.
 *
 * `starts`/`ends` are hours within a single day, and a multi-day exemption
 * cannot carry them (`exemptions_span_is_whole_days`). `spans_days` is that
 * distinction computed once here rather than re-derived from two date strings
 * in the page.
 *
 * @mixin Exemption
 */
class ExemptionResource extends JsonResource
{
    /** @return array<string, mixed> */
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

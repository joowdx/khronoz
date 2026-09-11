<?php

namespace App\Http\Requests;

use App\Models\Roster;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issuing a roster to the people picked on the grid.
 *
 * `anchor` is not derived from `starts` and is not allowed to be: cycle day 1
 * belongs to the cohort, not to the day a person joined it. Three hospital
 * teams on one 21-day schedule differ only by anchors seven days apart, so
 * defaulting one to the other would quietly collapse a rotation into a single
 * shift for everybody (04-scheduling.md, worked example 4).
 *
 * Nothing here checks for an overlapping roster. That is deliberate:
 * `rosters_no_overlap` is an exclusion constraint and the only authority on the
 * question, and a pre-check would race it and lie. The controller surfaces its
 * 23P01 instead.
 */
class StoreRosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Roster::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $agency = app(Tenant::class)->id();

        return [
            'employees' => ['required', 'array', 'min:1'],
            'employees.*' => [
                'string',
                Rule::exists('employees', 'id')
                    ->where('agency_id', $agency)
                    ->where(fn (Builder $query) => $query->whereNull('deleted_at')),
            ],
            'schedule_id' => ['required', 'string', Rule::exists('schedules', 'id')->where('agency_id', $agency)],
            'anchor' => ['required', 'date_format:Y-m-d'],
            'starts' => ['required', 'date_format:Y-m-d'],
            'ends' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employees.required' => 'Select somebody first',
            'employees.*.exists' => 'Not found',
            'schedule_id.required' => 'Choose a schedule',
            'schedule_id.exists' => 'Not found',
            'anchor.required' => 'Required',
            'starts.required' => 'Required',
            'ends.after_or_equal' => 'Cannot end before it starts',
        ];
    }
}

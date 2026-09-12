<?php

namespace App\Http\Requests;

use App\Models\Roster;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

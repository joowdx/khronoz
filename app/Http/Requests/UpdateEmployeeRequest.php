<?php

namespace App\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tags' => $this->input('tags', []),
            'exempt' => $this->boolean('exempt'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'number' => ['required', 'string', 'max:255', Rule::unique('employees', 'number')->where('agency_id', $employee->agency_id)->ignore($employee->id)],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:40', 'distinct'],
            'exempt' => ['boolean'],
            'cadence_id' => ['nullable', 'ulid', Rule::exists('cadences', 'id')->where('agency_id', $employee->agency_id)->where(fn (Builder $query) => $query->whereNull('retired_at')->orWhere('id', $employee->cadence_id))],
        ];
    }
}

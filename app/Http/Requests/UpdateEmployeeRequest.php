<?php

namespace App\Http\Requests;

use App\Enums\Sex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /** Default a wholly-unchecked tag list to [], the same way StoreEmployeeRequest does. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'tags' => $this->input('tags', []),
            'exempt' => $this->boolean('exempt'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'number' => ['required', 'string', 'max:255', Rule::unique('employees', 'number')->where('agency_id', $employee->agency_id)->ignore($employee->id)],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:255'],
            'sex' => ['nullable', Rule::enum(Sex::class)],
            'birthdate' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:254'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:255'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:40', 'distinct'],
            'exempt' => ['boolean'],
            'hired_at' => ['required', 'date'],
            'separated_at' => ['nullable', 'date', 'after_or_equal:hired_at'],
        ];
    }
}

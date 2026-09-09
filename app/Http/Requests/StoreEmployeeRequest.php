<?php

namespace App\Http\Requests;

use App\Enums\Sex;
use App\Models\Employee;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Employee::class);
    }

    /** Default a wholly-unchecked tag list to [], the same way StoreUserRequest defaults permissions. */
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
     * number is unique per agency (employees_agency_id_number_unique), not
     * globally — an agency's own numbering scheme may collide with another
     * agency's. That index is not partial, so a soft-deleted employee's
     * number stays taken; this rule matches that exactly by checking the raw
     * table rather than a Searchable-aware or trashed-excluding query.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:255', Rule::unique('employees', 'number')->where('agency_id', app(Tenant::class)->id())],
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

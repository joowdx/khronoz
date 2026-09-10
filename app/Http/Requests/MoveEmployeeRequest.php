<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Moving an employee is a change to that employee, so it is gated by
     * EmployeePolicy::update — the same ability the employee's own edit
     * screen uses — rather than a separate ability on Unit or on a
     * standalone "deployment" concept.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * unit_id is a paired FK target (unit_id, agency_id) once MoveEmployee
     * writes it, so the exists check is scoped to the employee's own
     * agency_id explicitly — a plain Rule::exists only proves the id exists
     * somewhere, not that it belongs to this tenant.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'string', Rule::exists('units', 'id')->where('agency_id', $this->route('employee')->agency_id)],
            'starts' => ['required', 'date'],
        ];
    }
}

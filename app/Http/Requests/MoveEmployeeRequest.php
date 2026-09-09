<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * R17: nothing in the database stops a deployment from starting before
     * the employee's hired_at or extending past separated_at —
     * docs/design/07-constraints.md:118-121 records that gap as deliberately
     * deferred, because the dates live on the employees row and Postgres
     * cannot express a cross-table constraint declaratively without a
     * trigger the design has not added (carried to Milestone 4). This is the
     * ONLY place that window is checked.
     *
     * Being application-tier only, it is honestly incomplete: a queue
     * worker, a bulk import, or a future API that inserts a deployment
     * directly bypasses it entirely, the same way it bypasses any other
     * validation rule. Every OTHER rule of consequence in this project is
     * enforced by the database (docs/design/07-constraints.md); this one
     * deliberately is not, and this comment exists so that gap stays visible
     * rather than being mistaken for an oversight.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('starts')) {
                return;
            }

            /** @var Employee $employee */
            $employee = $this->route('employee');
            $starts = $this->date('starts');

            if ($starts->lt($employee->hired_at)) {
                $validator->errors()->add('starts', 'Before the hire date.');
            } elseif ($employee->separated_at !== null && $starts->gt($employee->separated_at)) {
                $validator->errors()->add('starts', 'After the separation date.');
            }
        }];
    }
}

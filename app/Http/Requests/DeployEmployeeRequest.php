<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DeployEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Deploying an employee is a change to that employee, so it is gated by
     * EmployeePolicy::update — the same ability the employee's own edit
     * screen uses — rather than a separate ability on Workgroup or on a
     * standalone "deployment" concept. A reassignment is the same change to
     * the same person and needs no ability of its own.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `reassignment` is the whole discriminator (decision 35): absent or
     * false is a transfer, true is a reassignment. The parent is never sent,
     * because it is always the employee's own open placement — so this
     * request has no id to validate, only a boolean to read.
     *
     * workgroup_id is a paired FK target (workgroup_id, agency_id) once the
     * action writes it, so the exists check is scoped to the employee's own
     * agency_id explicitly — a plain Rule::exists only proves the id exists
     * somewhere, not that it belongs to this tenant.
     *
     * `ends` is nullable on both paths. A transfer normally leaves it null,
     * an open-ended placement; a fixed-term appointment has a known last day,
     * and a reassignment usually has one, since a detail runs for a period.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'workgroup_id' => ['required', 'string', Rule::exists('workgroups', 'id')->where('agency_id', $this->route('employee')->agency_id)],
            'starts' => ['required', 'date'],
            'ends' => ['nullable', 'date', 'after_or_equal:starts'],
            'reassignment' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Two checks a rule cannot express, both only for a reassignment, and
     * both mirroring a database refusal so the error lands on a field
     * instead of arriving as a translated SQLSTATE:
     *
     * 1. There must be an open placement to nest inside. Nothing in the
     *    schema can refuse this — with no parent, the row would be written as
     *    a valid substantive placement — so ReassignEmployee raises, and this
     *    turns the raise into a form error before it happens.
     * 2. The range must sit inside that placement's. deployments_nested is
     *    the guarantee (P0001); this is the message.
     *
     * `after()` and not `rules()` because both need the resolved parent,
     * and neither should fire while `starts` is already invalid.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->boolean('reassignment') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $placement = $this->route('employee')->currentDeployment;

            if ($placement === null) {
                $validator->errors()->add('reassignment', 'This employee has no open placement to be reassigned from.');

                return;
            }

            if ($this->date('starts')->lt($placement->starts)) {
                $validator->errors()->add('starts', 'Before the current placement began.');
            }

            // Only a *fixed-term* placement can be outlived. A null `ends` on
            // the placement is an unbounded upper bound and nothing can reach
            // past it — which is also why an open-ended reassignment is
            // refused under a fixed-term placement: `daterange(a, b, '[]')`
            // with a null upper bound is unbounded, and a closed range cannot
            // contain it. Exactly what deployments_nested answers with @>;
            // this only puts the message on the field.
            if ($placement->ends !== null && ($this->date('ends')?->gt($placement->ends) ?? true)) {
                $validator->errors()->add('ends', 'After the current placement ends.');
            }
        }];
    }
}

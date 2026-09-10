<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EndEmployeeDeploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /**
     * `deployment` and `expects` together are the expected-value predicate
     * this endpoint needs, and neither is optional.
     *
     * Ending "the open placement of this employee" was a stale-write hole
     * that the 2026-09-10 adversarial review found and decision 30
     * reclassified as a security defect, because deployment ranges are access
     * control: a form rendered before a concurrent transfer, submitted after
     * it, closed whatever row happened to be open *then* — which is the
     * **replacement** placement, in a workgroup the clerk never saw, granting
     * or removing visibility they never intended to touch.
     *
     * `deployment` names the row the form was rendered for, so the write
     * cannot wander to a different one. `expects` carries that row's `ends`
     * as rendered — null for an open placement, a date for a fixed-term one —
     * so a concurrent change to the same row is detected rather than
     * overwritten. Both are needed: the id answers "which row", `expects`
     * answers "unchanged since I looked".
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ends' => ['required', 'date'],
            'deployment' => [
                'required',
                'string',
                Rule::exists('deployments', 'id')
                    ->where('employee_id', $this->route('employee')->id)
                    ->whereNull('parent_id'),
            ],
            'expects' => ['present', 'nullable', 'date'],
        ];
    }

    /**
     * Give the date error before writing; the controller still translates
     * deployments_dates_ordered if a concurrent move changes the placement.
     * A row that is no longer the one this form saw is an operation error
     * handled by the conditional UPDATE, not a field error about the date the
     * clerk entered.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $starts = $this->route('employee')->deployments()
                ->whereKey($this->validated('deployment'))
                ->value('starts');

            if ($starts !== null && $this->date('ends')->lt($starts)) {
                $validator->errors()->add('ends', 'Before the current placement began.');
            }
        }];
    }
}

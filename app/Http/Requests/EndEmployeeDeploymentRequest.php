<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EndEmployeeDeploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['ends' => ['required', 'date']];
    }

    /**
     * Give the date error before writing; the controller still translates
     * deployments_dates_ordered if a concurrent move changes the placement.
     * No open row is an operation error handled by the conditional UPDATE,
     * not a field error about the date the clerk entered.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('ends')) {
                return;
            }

            $starts = $this->route('employee')->currentDeployment?->starts;

            if ($starts !== null && $this->date('ends')->lt($starts)) {
                $validator->errors()->add('ends', 'Before the current placement began.');
            }
        }];
    }
}

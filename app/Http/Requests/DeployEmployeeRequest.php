<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DeployEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'workgroup_id' => ['required', 'string', Rule::exists('workgroups', 'id')->where('agency_id', $this->route('employee')->agency_id)],
            'starts' => ['required', 'date'],
            'ends' => ['nullable', 'date', 'after_or_equal:starts'],
            'reassignment' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
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

            if ($placement->ends !== null && ($this->date('ends')?->gt($placement->ends) ?? true)) {
                $validator->errors()->add('ends', 'After the current placement ends.');
            }
        }];
    }
}

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

    /** @return array<string, array<int, mixed>> */
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

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $placement = $this->route('employee')->deployments()
                ->whereKey($this->validated('deployment'))
                ->first(['starts', 'ends']);

            if ($placement === null) {
                return;
            }

            if ($this->date('ends')->lt($placement->starts)) {
                $validator->errors()->add('ends', 'Before the current placement began.');
            }

            if ($this->date('expects')?->toDateString() !== $placement->getRawOriginal('ends')) {
                $validator->errors()->add('ends', 'This placement changed since the form was opened. Reload and try again.');
            }
        }];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ManageAgency->value);
    }

    public function rules(): array
    {
        return ['template' => ['nullable', Rule::in(['form48', 'plain'])],
            'roles' => ['nullable', 'array', 'list', 'min:1', 'max:4'],
            'roles.*' => ['required', Rule::in(['employee', 'supervisor', 'head', 'timekeeper']), 'distinct'],
            'supervisor' => ['nullable', Rule::in(['operative', 'substantive'])],
            'head_kind' => ['nullable', Rule::requiredIf(in_array('head', (array) $this->input('roles', []), true)), 'string', 'max:255'], ];
    }
}

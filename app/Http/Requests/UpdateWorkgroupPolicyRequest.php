<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkgroupPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ManageAgency->value);
    }

    public function rules(): array
    {
        $minimumRoles = $this->input('template') === 'form48' ? 2 : 1;

        return ['template' => ['nullable', Rule::in(['form48', 'plain'])],
            'roles' => ['nullable', 'array', 'list', 'min:'.$minimumRoles, 'max:4'],
            'roles.*' => ['required', Rule::in(['employee', 'supervisor', 'head', 'timekeeper']), 'distinct'],
            'supervisor' => ['nullable', Rule::in(['operative', 'substantive'])],
            'head_kind' => ['nullable', Rule::requiredIf(in_array('head', (array) $this->input('roles', []), true)), 'string', 'max:255'], ];
    }

    public function messages(): array
    {
        return $this->input('template') === 'form48'
            ? ['roles.min' => 'CSC Form 48 requires at least two attestation roles.']
            : [];
    }
}

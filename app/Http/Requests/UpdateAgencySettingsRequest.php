<?php

namespace App\Http\Requests;

use App\Enums\MissingSide;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgencySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ManageAgency->value);
    }

    public function rules(): array
    {
        $minimumRoles = $this->input('policy.template') === 'form48' ? 2 : 1;

        return [
            'settings' => ['required', 'array:ledger_archiving,night_from,overtime_after_weekly,occurrences,suspension_charge,premium_hours,overtime_gates,missing_side'],
            'settings.ledger_archiving' => ['sometimes', 'boolean'],
            'settings.night_from' => ['sometimes', 'date_format:H:i'],
            'settings.overtime_after_weekly' => ['nullable', 'integer', 'between:1,168'],
            'settings.occurrences' => ['sometimes', 'boolean'],
            'settings.suspension_charge' => ['sometimes', 'boolean'],
            'settings.premium_hours' => ['sometimes', 'boolean'],
            'settings.overtime_gates' => ['sometimes', 'boolean'],
            'settings.missing_side' => ['sometimes', Rule::enum(MissingSide::class)],
            'policy' => ['sometimes', 'array:template,roles,supervisor,head_kind'],
            'policy.template' => ['nullable', Rule::in(['form48', 'plain'])],
            'policy.roles' => ['nullable', 'array', 'list', 'min:'.$minimumRoles, 'max:4'],
            'policy.roles.*' => ['required', Rule::in(['employee', 'supervisor', 'head', 'timekeeper']), 'distinct'],
            'policy.supervisor' => ['nullable', Rule::in(['operative', 'substantive'])],
            'policy.head_kind' => ['nullable', Rule::requiredIf(in_array('head', (array) $this->input('policy.roles', []), true)), 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return $this->input('policy.template') === 'form48'
            ? ['policy.roles.min' => 'CSC Form 48 requires at least two attestation roles.']
            : [];
    }
}

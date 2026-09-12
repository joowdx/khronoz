<?php

namespace App\Http\Requests;

use App\Enums\TerminalKind;
use App\Enums\TerminalProtocol;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('terminal'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $agency = app(Tenant::class)->id();

        return [
            'code' => [
                'required', 'string', 'max:255',
                Rule::unique('terminals', 'code')->where('agency_id', $agency)->ignore($this->route('terminal')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'serial' => ['nullable', 'string', 'max:255', Rule::unique('terminals', 'serial')->ignore($this->route('terminal'))],
            'kind' => ['required', Rule::enum(TerminalKind::class)],
            'protocol' => ['required', Rule::enum(TerminalProtocol::class)],
            'workgroup_id' => [
                'nullable', 'string',
                Rule::exists('workgroups', 'id')->where('agency_id', $agency),
            ],
            'active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'Another terminal of this agency already uses that device number.',
            'serial.unique' => 'That serial is already registered.',
            'workgroup_id.exists' => 'Not found',
        ];
    }
}

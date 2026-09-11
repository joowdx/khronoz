<?php

namespace App\Http\Requests;

use App\Enums\TerminalKind;
use App\Enums\TerminalProtocol;
use App\Models\Terminal;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('terminal'));
    }

    /**
     * `code` is **not** upper-cased or otherwise normalised the way
     * StoreWorkgroupRequest normalises its own, and that is deliberate: this is
     * the number the device stamps into every line of its attlog, compared
     * byte-for-byte against the file at import (decision 44), so the value
     * typed here has to be the value the device emits. Normalising it would
     * make a file silently refuse to import against the terminal it came from.
     *
     * The uniqueness rules mirror the two database constraints exactly, and
     * both are scoped the way the index is:
     *
     * - `UNIQUE (agency_id, code)` — two terminals of one agency cannot share
     *   a device number, because the attlog identifies its device by that
     *   number alone.
     * - `terminals_serial`, a **partial** unique index over the whole table —
     *   a manufacturer's serial does not repeat across tenants, so this one is
     *   deliberately not scoped to the agency, and `Rule::unique` without a
     *   tenant clause is the honest mirror of it.
     *
     * @return array<string, mixed>
     */
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

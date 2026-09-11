<?php

namespace App\Http\Requests;

use App\Models\Terminal;
use Illuminate\Foundation\Http\FormRequest;

class VoidTimelogRequest extends FormRequest
{
    /**
     * Voiding is the only mutation this table allows, and it is gated on
     * `terminals.manage` rather than a permission of its own: marking a punch
     * bad changes what somebody is paid, which is the same weight as admitting
     * the file it came from.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Terminal::class);
    }

    /**
     * `reason` is required by the database too
     * (`timelogs_void_needs_reason`), and the rule here exists to put the
     * refusal on the field rather than in a 500. An unexplained void takes a
     * punch out of the record with nothing to audit, which is worse than
     * leaving it standing.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this punch is being voided — it stays on the record either way.',
        ];
    }
}

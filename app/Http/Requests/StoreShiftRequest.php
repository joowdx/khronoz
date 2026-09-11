<?php

namespace App\Http\Requests;

use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;

class StoreShiftRequest extends FormRequest
{
    /**
     * Authorization goes through the gate, never `$this->user()->allows(…)`
     * directly — `Gate::before` is where platform superusers are granted every
     * ability, and `User::allows()` bypasses it entirely (requests.md).
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Shift::class);
    }

    /**
     * Basic Laravel validation only. The four CHECK constraints
     * (`slots_valid`, `shifts_remote_has_no_slots`, `shifts_off_credits_nothing`,
     * `shifts_off_has_no_flex`) are owned by a later hardening pass and are
     * intentionally left untranslated here — do not add a custom rule class.
     *
     * Slot times are validated as strings matching `/^\d{1,2}:[0-5]\d$/`; values
     * past 24:00 (e.g. `"30:00"` for 06:00 the next day, capped at 72:00) are
     * valid and intentional — the engine's `slots_valid()` is the last word on
     * their arithmetic (04-scheduling.md).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slots' => ['present', 'array'],
            'slots.*.in' => ['required', 'string', 'regex:/^\d{1,2}:[0-5]\d$/'],
            'slots.*.out' => ['required', 'string', 'regex:/^\d{1,2}:[0-5]\d$/'],
            'slots.*.grace' => ['integer', 'min:0'],
            'slots.*.window' => ['required', 'array', 'size:2'],
            'slots.*.window.0' => ['required', 'integer'],
            'slots.*.window.1' => ['required', 'integer'],
            'required' => ['required', 'integer', 'min:0'],
            'flex' => ['required', 'integer', 'min:0'],
            'color' => ['required', 'integer', 'between:1,8'],
            'remote' => ['required', 'boolean'],
            'trust' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slots.*.in.regex' => 'Use HH:MM format (e.g. 08:00 or 30:00 for the next day).',
            'slots.*.out.regex' => 'Use HH:MM format (e.g. 17:00 or 30:00 for the next day).',
            'slots.*.window.size' => 'The window must have exactly two values.',
        ];
    }
}

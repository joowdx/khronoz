<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('shift'));
    }

    /** @return array<string, mixed> */
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

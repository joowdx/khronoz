<?php

namespace App\Http\Requests;

use App\Models\Terminal;
use Illuminate\Foundation\Http\FormRequest;

class VoidTimelogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Terminal::class);
    }

    /** @return array<string, mixed> */
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

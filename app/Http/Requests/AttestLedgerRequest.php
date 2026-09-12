<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttestLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attest', $this->route('ledger'));
    }

    public function rules(): array
    {
        return ['role' => ['nullable', Rule::in(['employee', 'supervisor', 'head', 'timekeeper'])]];
    }
}

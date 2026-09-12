<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnlockLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('unlock', $this->route('ledger'));
    }

    public function rules(): array
    {
        return [];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetryLedgerDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('retry', $this->route('rendition'));
    }

    public function rules(): array
    {
        return [];
    }
}

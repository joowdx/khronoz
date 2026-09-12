<?php

namespace App\Http\Requests;

use App\Support\Legal;
use Illuminate\Foundation\Http\FormRequest;

class StoreAcceptanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accept-legal');
    }

    public function rules(): array
    {
        $rules = ['documents' => ['required', 'array:privacy-policy,user-agreement']];

        foreach (Legal::DOCUMENTS as $document) {
            $rules["documents.{$document}"] = ['required', 'array:version,hash,accepted'];
            $rules["documents.{$document}.version"] = ['required', 'string', 'max:255'];
            $rules["documents.{$document}.hash"] = ['required', 'string', 'size:64'];
            $rules["documents.{$document}.accepted"] = ['required', 'accepted'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'documents.*.accepted.required' => 'Please check this acknowledgment.',
            'documents.*.accepted.accepted' => 'Please check this acknowledgment.',
        ];
    }
}

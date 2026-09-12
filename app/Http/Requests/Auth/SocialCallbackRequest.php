<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SocialCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['state' => ['required', 'string', 'size:64', 'alpha_num:ascii'],
            'code' => ['required_without:error', 'nullable', 'string', 'max:4096'],
            'error' => ['nullable', 'string', 'max:255']];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(redirect()->route('social.failure', status: 303));
    }
}

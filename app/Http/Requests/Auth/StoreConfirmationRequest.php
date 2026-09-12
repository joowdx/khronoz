<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class StoreConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-account');
    }

    public function rules(): array
    {
        return ['password' => ['required', 'string', 'current_password:web'],
            'destination' => ['nullable', 'string', 'max:2048'], ];
    }
}

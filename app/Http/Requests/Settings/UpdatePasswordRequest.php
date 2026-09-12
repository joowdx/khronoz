<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-account');
    }

    public function rules(): array
    {
        return ['password' => ['required', 'confirmed', Password::defaults()]];
    }
}

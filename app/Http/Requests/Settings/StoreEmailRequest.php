<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-account');
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255']];
    }
}

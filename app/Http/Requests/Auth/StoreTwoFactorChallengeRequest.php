<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class StoreTwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['nullable', 'required_without:recovery_code', 'digits:6'], 'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:100']];
    }
}

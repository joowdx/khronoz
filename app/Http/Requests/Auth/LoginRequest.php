<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): User
    {
        $this->session()->forget('login');
        $provider = Auth::guard('web')->getProvider();
        $credentials = ['email' => strtolower(trim($this->validated('email'))), 'password' => $this->validated('password')];
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            throw ValidationException::withMessages(['form' => __('auth.failed')]);
        }

        if ($user->invited_at !== null && $user->email_verified_at === null) {
            throw ValidationException::withMessages(['form' => __('auth.invited')]);
        }
        $provider->rehashPasswordIfRequired($user, $credentials);

        return $user;
    }
}

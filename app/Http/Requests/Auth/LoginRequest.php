<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * The message never distinguishes a wrong password from an unknown
     * email (`__('auth.failed')` both times), so a login attempt cannot be
     * used to discover which emails have an account.
     */
    public function authenticate(): void
    {
        $credentials = $this->only('email', 'password');

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // InviteUser gives an invited user an unguessable random password, so
        // in practice Auth::attempt() above cannot succeed for one until they
        // follow their emailed link. This check is the guard of record for
        // that rule regardless: it does not depend on the password being
        // unguessable, only on invited_at/email_verified_at, so it still
        // refuses the account if a password were ever set another way.
        if (Auth::user()->invited_at !== null && Auth::user()->email_verified_at === null) {
            Auth::logout();

            throw ValidationException::withMessages(['email' => 'This invitation has not been accepted yet. Use the link in your email.']);
        }
    }
}

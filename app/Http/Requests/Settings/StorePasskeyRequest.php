<?php

namespace App\Http\Requests\Settings;

use App\Support\PasskeyChallenge;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Webauthn\PublicKeyCredentialCreationOptions;

class StorePasskeyRequest extends PasskeyRegistrationRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-account');
    }

    public function rules(): array
    {
        return [...parent::rules(), 'name' => ['required', 'string', 'max:100']];
    }

    public function registrationOptions(): PublicKeyCredentialCreationOptions
    {
        return PasskeyChallenge::take($this, 'registration', PublicKeyCredentialCreationOptions::class);
    }
}

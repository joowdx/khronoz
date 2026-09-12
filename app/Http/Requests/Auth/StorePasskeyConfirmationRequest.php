<?php

namespace App\Http\Requests\Auth;

use App\Support\PasskeyChallenge;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Webauthn\PublicKeyCredentialRequestOptions;

class StorePasskeyConfirmationRequest extends PasskeyVerificationRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-account');
    }

    public function rules(): array
    {
        return [...parent::rules(), 'destination' => ['nullable', 'string', 'max:2048']];
    }

    public function verificationOptions(): PublicKeyCredentialRequestOptions
    {
        return PasskeyChallenge::take($this, 'confirmation', PublicKeyCredentialRequestOptions::class);
    }
}

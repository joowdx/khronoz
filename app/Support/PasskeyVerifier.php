<?php

namespace App\Support;

use App\Models\Passkey;
use App\Models\Scopes\AgencyScope;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

class PasskeyVerifier extends VerifyPasskey
{
    public function validate(AuthenticatorAssertionResponse $response, \Laravel\Passkeys\Passkey $passkey, PublicKeyCredentialRequestOptions $options): CredentialRecord
    {
        try {
            return parent::validate($response, $passkey, $options);
        } catch (\InvalidArgumentException $exception) {
            throw InvalidPasskeyException::make('Passkey verification failed. Please try again.');
        }
    }

    public function getPasskey(PublicKeyCredential $credential, bool $lock = false): Passkey
    {
        // A credential identifies its owner before a tenant exists; signature verification follows this lookup.
        return Passkey::withoutGlobalScope(AgencyScope::class)
            ->where('credential_id', Base64UrlSafe::encodeUnpadded($credential->rawId))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first()
            ?? throw InvalidPasskeyException::make('This passkey is not recognized.');
    }
}

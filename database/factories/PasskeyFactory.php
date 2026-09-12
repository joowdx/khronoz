<?php

namespace Database\Factories;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/** @extends Factory<Passkey> */
class PasskeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'agency_id' => fn (array $attributes) => User::findOrFail($attributes['user_id'])->agency_id,
            'name' => fake()->word(),
            'credential_id' => Base64UrlSafe::encodeUnpadded(random_bytes(32)),
            'credential' => function (array $attributes): array {
                $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
                $ec = openssl_pkey_get_details($key)['ec'];
                $source = CredentialRecord::create(
                    Base64UrlSafe::decodeNoPadding($attributes['credential_id']), 'public-key', ['internal'], 'none',
                    EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'),
                    hex2bin('a5010203262001215820').$ec['x'].hex2bin('225820').$ec['y'],
                    User::findOrFail($attributes['user_id'])->getPasskeyUserHandle(), 0, backupEligible: false, backupStatus: false, uvInitialized: true,
                );

                return json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR);
            },
            'last_used_at' => null,
        ];
    }
}

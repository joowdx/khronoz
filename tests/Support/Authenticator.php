<?php

namespace Tests\Support;

use App\Models\User;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;

/** Software authenticator producing real P-256 signatures for WebAuthn boundary tests. */
final class Authenticator
{
    private OpenSSLAsymmetricKey $key;

    public string $id;

    private int $counter = 0;

    public function __construct()
    {
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->id = random_bytes(32);
    }

    public function registration(array $options, ?string $origin = null, bool $verified = true): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $cose = hex2bin('a5010203262001215820').$ec['x'].hex2bin('225820').$ec['y'];
        $data = hash('sha256', $options['rp']['id'], true).chr($verified ? 0x45 : 0x41).pack('N', 0)
            .str_repeat(chr(0), 16).pack('n', strlen($this->id)).$this->id.$cose;
        $attestation = hex2bin('a363666d74646e6f6e656761747453746d74a0686175746844617461').chr(0x58).chr(strlen($data)).$data;

        return $this->credential([
            'attestationObject' => Base64UrlSafe::encodeUnpadded($attestation),
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($this->client($options, 'webauthn.create', $origin)),
            'transports' => ['internal'],
        ]);
    }

    public function assertion(array $options, User $user, ?string $origin = null, bool $verified = true): array
    {
        $client = $this->client($options, 'webauthn.get', $origin);
        $data = hash('sha256', $options['rpId'], true).chr($verified ? 0x05 : 0x01).pack('N', ++$this->counter);
        openssl_sign($data.hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $this->credential([
            'authenticatorData' => Base64UrlSafe::encodeUnpadded($data),
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($client),
            'signature' => Base64UrlSafe::encodeUnpadded($signature),
            'userHandle' => Base64UrlSafe::encodeUnpadded($user->getPasskeyUserHandle()),
        ]);
    }

    private function client(array $options, string $type, ?string $origin): string
    {
        return json_encode(['type' => $type, 'challenge' => $options['challenge'], 'origin' => $origin ?? config('app.url'), 'crossOrigin' => false], JSON_THROW_ON_ERROR);
    }

    private function credential(array $response): array
    {
        return ['id' => Base64UrlSafe::encodeUnpadded($this->id), 'rawId' => Base64UrlSafe::encodeUnpadded($this->id), 'type' => 'public-key', 'response' => $response, 'clientExtensionResults' => (object) []];
    }
}

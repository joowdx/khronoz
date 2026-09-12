<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;

final class PasskeyChallenge
{
    public static function issue(Request $request, string $purpose, object $options): JsonResponse
    {
        $request->session()->put('passkey.'.$purpose, [
            'options' => WebAuthn::toJson($options), 'expires' => now()->addMinute()->timestamp,
            'user' => $request->user()?->getKey(), 'password_hash' => $request->user()?->password,
        ]);

        return response()->json(['options' => WebAuthn::toBrowserArray($options)])->header('Cache-Control', 'no-store, private');
    }

    /** @template T of object
     * @param  class-string<T>  $type
     * @return T
     */
    public static function take(Request $request, string $purpose, string $type): object
    {
        $challenge = $request->session()->pull('passkey.'.$purpose);
        if (! is_array($challenge) || ($challenge['expires'] ?? 0) <= now()->timestamp
            || $challenge['user'] !== $request->user()?->getKey()
            || $challenge['password_hash'] !== $request->user()?->password) {
            throw InvalidPasskeyException::make('This passkey request expired. Please try again.');
        }

        return WebAuthn::fromJson($challenge['options'], $type);
    }
}

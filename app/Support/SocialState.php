<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SocialState
{
    public static function matches(Request $request, string $provider, mixed $state): bool
    {
        $flow = $request->session()->get('oauth');

        return is_array($flow) && is_string($state) && hash_equals($flow['state'], $state)
            && $flow['provider'] === $provider && $flow['expires'] > now()->timestamp
            && $flow['user'] === $request->user()?->getKey()
            && $flow['password_hash'] === $request->user()?->password;
    }

    public static function take(Request $request, string $provider, mixed $state): array
    {
        $valid = self::matches($request, $provider, $state);
        $flow = $request->session()->pull('oauth');
        if (! $valid || ($flow['purpose'] === 'link' && (! Authentication::confirmed($request) || ! $request->user()?->hasVerifiedEmail()))) {
            throw ValidationException::withMessages(['form' => 'This sign-in request expired or belongs to another session. Please start again.']);
        }

        return $flow;
    }
}

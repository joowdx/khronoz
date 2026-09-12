<?php

namespace App\Support;

use Illuminate\Http\Request;

final class Authentication
{
    public static function confirmed(Request $request): bool
    {
        $time = $request->session()->get('auth.password_confirmed_at', 0);

        return $request->session()->get('auth.confirmed_user') === $request->user()?->getKey()
            && is_int($time) && $time > now()->timestamp - 300 && $time <= now()->timestamp;
    }

    public static function confirm(Request $request): void
    {
        $request->session()->passwordConfirmed();
        $request->session()->put('auth.confirmed_user', $request->user()->getKey());
    }

    public static function destination(mixed $value, string $fallback = '/settings/security'): string
    {
        return is_string($value) && str_starts_with($value, '/') && ! str_starts_with($value, '//')
            && ! str_contains($value, '\\') && ! preg_match('/[\x00-\x20]/', $value)
            ? $value : $fallback;
    }
}

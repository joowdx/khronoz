<?php

namespace App\Support;

use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SocialProvider
{
    /** @return array{google: bool, apple: bool} */
    public static function availability(): array
    {
        return ['google' => self::available('google'), 'apple' => self::available('apple')];
    }

    public static function available(string $provider): bool
    {
        if (! in_array($provider, ['google', 'apple'], true)) {
            return false;
        }
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            $value = config("services.$provider.$key");
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    public function redirect(Request $request, string $provider, string $state, string $nonce): RedirectResponse
    {
        return Socialite::driver($provider)->setRequest($request)->stateless()
            ->setScopes($provider === 'apple' ? ['email'] : ['openid', 'email'])
            ->with(['state' => $state, 'nonce' => $nonce])->redirect();
    }

    public function user(Request $request, string $provider, array $payload, string $nonce): User
    {
        // State is verified and consumed by SocialState before any exchange; Apple never relies on a POST session cookie.
        $callback = Request::create(config("services.$provider.redirect"), 'GET', ['code' => $payload['code']]);
        $driver = Socialite::driver($provider)->setRequest($callback)->stateless()->with([]);
        $tokens = $driver->getAccessTokenResponse($payload['code']);
        if ($provider === 'apple') {

            return $driver->userByIdentityToken($tokens['id_token'], $nonce);
        }

        return $driver->userFromToken($tokens['access_token']);
    }
}

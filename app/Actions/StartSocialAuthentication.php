<?php

namespace App\Actions;

use App\Support\SocialProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class StartSocialAuthentication
{
    public function handle(Request $request, string $provider, string $purpose): RedirectResponse
    {
        abort_unless(SocialProvider::available($provider), 404);
        $state = Str::random(64);
        $nonce = Str::random(64);
        $request->session()->forget('login');
        $request->session()->put('oauth', [
            'state' => $state, 'nonce' => $nonce, 'provider' => $provider, 'purpose' => $purpose,
            'user' => $request->user()?->getKey(), 'password_hash' => $request->user()?->password,
            'expires' => now()->addMinutes(5)->timestamp,
        ]);

        return app(SocialProvider::class)->redirect($request, $provider, $state, $nonce);
    }
}

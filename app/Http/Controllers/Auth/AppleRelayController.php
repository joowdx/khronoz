<?php

namespace App\Http\Controllers\Auth;

use App\Actions\CompleteSocialAuthentication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreAppleRelayRequest;
use App\Support\SocialProvider;
use App\Support\SocialState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class AppleRelayController extends Controller
{
    /** This route has no web/session middleware: Apple's cross-site POST must never replace the original session cookie. */
    public function store(StoreAppleRelayRequest $request): RedirectResponse
    {
        abort_unless(SocialProvider::available('apple'), 404);
        $relay = Str::random(64);
        Cache::put('oauth-relay:'.$relay, Crypt::encryptString(json_encode($request->validated(), JSON_THROW_ON_ERROR)), now()->addMinutes(2));

        return redirect()->route('social.apple.complete', $relay, 303)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function complete(Request $request, string $relay, CompleteSocialAuthentication $complete): RedirectResponse
    {
        $key = 'oauth-relay:'.$relay;
        $encrypted = Cache::get($key);
        if (! is_string($encrypted)) {
            return redirect()->route('social.failure');
        }
        $payload = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        if (! SocialState::matches($request, 'apple', $payload['state'])) {
            return redirect()->route('social.failure');
        }
        $consumed = Cache::lock($key.':lock', 10)->block(3, fn () => Cache::pull($key));
        if ($consumed === null) {
            return redirect()->route('social.failure');
        }

        return $complete->handle($request, 'apple', $payload)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}

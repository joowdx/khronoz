<?php

namespace App\Http\Controllers\Auth;

use App\Actions\CompleteSocialAuthentication;
use App\Actions\StartSocialAuthentication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialCallbackRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse as ProviderRedirect;

class SocialController extends Controller
{
    public function redirect(Request $request, string $provider, StartSocialAuthentication $start): ProviderRedirect
    {
        return $start->handle($request, $provider, 'login');
    }

    public function callback(SocialCallbackRequest $request, CompleteSocialAuthentication $complete): RedirectResponse
    {
        return $complete->handle($request, 'google', $request->validated());
    }

    public function failure(Request $request): RedirectResponse
    {
        return redirect()->route($request->user() ? 'settings.connections.index' : 'login')->with('error', 'This provider request is invalid or expired. Please start again.');
    }
}

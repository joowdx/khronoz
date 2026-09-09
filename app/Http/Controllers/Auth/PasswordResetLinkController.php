<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the forgot password page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        // Every broker outcome — a registered address (ResetLinkSent), an
        // unregistered one (InvalidUser), or a repeat request too soon after
        // the last one (ResetThrottled) — deliberately produces this same
        // neutral response. The broker still only emails an address that is
        // actually registered; what changes here is that the HTTP response
        // itself no longer tells the caller which case happened, so this
        // endpoint cannot be used to test whether a given address has an
        // account in a government HR system. The login endpoint's single
        // generic __('auth.failed') failure (LoginRequest::authenticate())
        // is the same policy applied there.
        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'If that address is registered, a reset link is on its way.');
    }
}

<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BeginLogin
{
    public function handle(Request $request, User $user, bool $remember = false): RedirectResponse
    {
        $request->session()->forget(['login', 'auth.confirmed_user', 'auth.password_confirmed_at']);
        $user->refresh();
        if ($user->invited_at !== null && $user->email_verified_at === null) {
            throw ValidationException::withMessages(['form' => __('auth.invited')]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->regenerate();
            $request->session()->put('login', [
                'id' => $user->getKey(), 'remember' => $remember, 'expires' => now()->addMinutes(5)->timestamp,
                'password_hash' => $user->password, 'factor_hash' => hash('sha256', $user->two_factor_secret),
            ]);

            return redirect()->route('two-factor.login');
        }

        return app(CompleteLogin::class)->handle($request, $user, $remember);
    }
}

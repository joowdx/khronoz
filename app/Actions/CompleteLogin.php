<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Authentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class CompleteLogin
{
    public function handle(Request $request, User $user, bool $remember = false): RedirectResponse
    {
        if ($user->invited_at !== null && $user->email_verified_at === null) {
            throw ValidationException::withMessages(['form' => __('auth.invited')]);
        }
        Auth::guard('web')->login($user, $remember);
        $request->session()->forget(['login', 'agency', 'auth.confirmed_user', 'auth.password_confirmed_at', 'password_hash_web']);
        $request->session()->regenerate();
        $intended = $request->session()->pull('url.intended');
        if (is_string($intended) && str_starts_with($intended, rtrim(config('app.url'), '/').'/')) {
            $intended = substr($intended, strlen(rtrim(config('app.url'), '/')));
        }

        return redirect()->to(Authentication::destination($intended, route('dashboard')));
    }
}

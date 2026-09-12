<?php

namespace App\Http\Controllers\Auth;

use App\Actions\CompleteLogin;
use App\Actions\VerifySecondFactor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreTwoFactorChallengeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get('login.expires', 0) <= now()->timestamp) {
            $request->session()->forget('login');

            return redirect()->route('login')->with('error', 'Your sign-in attempt expired. Please sign in again.');
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function store(StoreTwoFactorChallengeRequest $request, VerifySecondFactor $verify, CompleteLogin $complete): RedirectResponse
    {
        return DB::transaction(function () use ($request, $verify, $complete): RedirectResponse {
            $pending = $request->session()->get('login', []);
            $user = isset($pending['id']) ? User::whereKey($pending['id'])->lockForUpdate()->first() : null;
            if (! $user || ($pending['expires'] ?? 0) <= now()->timestamp
                || ! hash_equals($user->password, $pending['password_hash'] ?? '')
                || ! $user->hasEnabledTwoFactorAuthentication()
                || ! hash_equals(hash('sha256', $user->two_factor_secret), $pending['factor_hash'] ?? '')) {
                $request->session()->forget('login');
                throw ValidationException::withMessages(['form' => 'Your sign-in attempt expired. Please sign in again.']);
            }

            $verify->handle($user, $request->validated('code'), $request->validated('recovery_code'));
            $request->session()->forget('login');

            return $complete->handle($request, $user, $pending['remember'] ?? false);
        });
    }
}

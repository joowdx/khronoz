<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInviteRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InviteController extends Controller
{
    public function create(Request $request, User $user): RedirectResponse|Response
    {
        return $this->alreadyAccepted($user) ?? Inertia::render('auth/accept-invite', [
            'user' => ['name' => $user->name, 'email' => $user->email],
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(AcceptInviteRequest $request, User $user): RedirectResponse
    {
        if ($redirect = $this->alreadyAccepted($user)) {
            return $redirect;
        }

        $user->forceFill([
            'password' => $request->password,
            'email_verified_at' => now(),
        ])->save();

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Welcome, '.$user->name.'.');
    }

    /**
     * Shared by create() and store(): an accepted invite's signed URL stays valid for the rest of its lifetime, so both verbs must refuse it, not just the one the browser visits first.
     */
    private function alreadyAccepted(User $user): ?RedirectResponse
    {
        return $user->email_verified_at !== null
            ? redirect()->route('login')->with('error', 'This invitation was already accepted. Sign in instead.')
            : null;
    }
}

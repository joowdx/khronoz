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
    /**
     * Display the accept-invite page, or send an already-accepted invite to
     * login instead — see store() for why that same check is repeated there.
     */
    public function create(Request $request, User $user): RedirectResponse|Response
    {
        return $this->alreadyAccepted($user) ?? Inertia::render('auth/accept-invite', [
            'user' => ['name' => $user->name, 'email' => $user->email],
            'action' => $request->fullUrl(),
        ]);
    }

    /**
     * Set the invited user's password and sign them in.
     *
     * The GET route above refuses an already-accepted invite, but its signed
     * URL stays cryptographically valid for the rest of its 7-day lifetime —
     * a browser never re-checks create() before submitting a form, and a POST
     * straight to this same URL is just as reachable. Repeating the check
     * here is what stops that still-valid link from being replayed to
     * overwrite a password the user already changed.
     */
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

        // Prevent session fixation here too: this and login are the only two
        // places an anonymous session becomes an authenticated one.
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Welcome, '.$user->name.'.');
    }

    /** Shared by create() and store(): an accepted invite's signed URL stays valid for the rest of its lifetime, so both verbs must refuse it, not just the one the browser visits first. */
    private function alreadyAccepted(User $user): ?RedirectResponse
    {
        return $user->email_verified_at !== null
            ? redirect()->route('login')->with('error', 'This invitation was already accepted. Sign in instead.')
            : null;
    }
}

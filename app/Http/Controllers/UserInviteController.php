<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class UserInviteController extends Controller
{
    /**
     * Re-send the invite email. Refuses an already-accepted invite — its
     * link would only confuse someone who has already signed in and set
     * their own password. InviteNotification (see its own doc comment) is
     * safe to send more than once: every send mints a fresh signed URL.
     */
    public function store(User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        if ($user->email_verified_at !== null) {
            return back()->with('error', "{$user->name} has already accepted their invitation.");
        }

        $user->notify(new InviteNotification);

        return back()->with('success', "Invitation sent again to {$user->email}.");
    }
}

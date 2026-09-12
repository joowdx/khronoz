<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class UserInviteController extends Controller
{
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

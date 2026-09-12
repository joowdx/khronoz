<?php

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;

/**
 * Gate::before (AppServiceProvider::configureAuthorization) already answers
 * true for every platform user before any of these run, so in practice this
 * class is never reached. It exists anyway to document who is meant to hold
 * these abilities and to keep the resource protected if the `platform`
 * middleware is ever moved off the route group.
 */
class AgencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatform();
    }

    public function view(User $user, Agency $agency): bool
    {
        return $user->isPlatform();
    }

    public function create(User $user): bool
    {
        return $user->isPlatform();
    }

    public function update(User $user, Agency $agency): bool
    {
        return $user->isPlatform();
    }

    public function delete(User $user, Agency $agency): bool
    {
        return $user->isPlatform();
    }

    public function restore(User $user, Agency $agency): bool
    {
        return $user->isPlatform();
    }

    public function forceDelete(User $user, Agency $agency): bool
    {
        return $user->isPlatform();
    }
}

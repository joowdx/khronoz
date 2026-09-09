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
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isPlatform();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Agency $agency): bool
    {
        return $user->isPlatform();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isPlatform();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Agency $agency): bool
    {
        return $user->isPlatform();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Agency $agency): bool
    {
        return $user->isPlatform();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Agency $agency): bool
    {
        return $user->isPlatform();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Agency $agency): bool
    {
        return $user->isPlatform();
    }
}

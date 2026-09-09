<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Unit;
use App\Models\User;

/**
 * Gate::before (AppServiceProvider::configureAuthorization) already answers
 * true for a platform user before any of these run, mirroring UserPolicy —
 * this class describes non-platform staff only.
 */
class UnitPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewOrganization);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Unit $unit): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Unit $unit): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }
}

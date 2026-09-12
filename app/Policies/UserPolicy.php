<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Access is a set of permissions on the user, not a role (docs/design/02-
 * access.md rule 4): every ability below reduces to whether the acting user
 * holds users.manage. Gate::before (AppServiceProvider::configureAuthorization)
 * already answers true for a platform user before any of these run, mirroring
 * AgencyPolicy.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ManageUsers);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageUsers);
    }

    public function update(User $user, User $target): bool
    {
        return $user->allows(Permission::ManageUsers);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->allows(Permission::ManageUsers) && ! $user->is($target);
    }
}

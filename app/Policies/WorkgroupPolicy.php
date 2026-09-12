<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\Workgroup;

/**
 * Gate::before (AppServiceProvider::configureAuthorization) already answers
 * true for a platform user before any of these run, mirroring UserPolicy —
 * this class describes non-platform staff only.
 */
class WorkgroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewOrganization);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }

    public function update(User $user, Workgroup $workgroup): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }

    public function delete(User $user, Workgroup $workgroup): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }
}

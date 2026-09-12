<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\User;

/**
 * Gate::before (AppServiceProvider::configureAuthorization) already answers
 * true for a platform user before any of these run, mirroring UserPolicy —
 * this class describes non-platform staff only.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewOrganization);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->allows(Permission::ViewOrganization);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }
}

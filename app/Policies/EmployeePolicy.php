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
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewOrganization);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Employee $employee): bool
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
     *
     * Also the ability MoveEmployeeRequest authorizes against: moving an
     * employee to a new workgroup is a change to that employee, not a distinct
     * resource of its own.
     */
    public function update(User $user, Employee $employee): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->allows(Permission::ManageOrganization);
    }
}

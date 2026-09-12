<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Shift;
use App\Models\User;

class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewScheduling);
    }

    public function view(User $user, Shift $shift): bool
    {
        return $user->allows(Permission::ViewScheduling);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }

    public function update(User $user, Shift $shift): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }

    public function delete(User $user, Shift $shift): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }
}

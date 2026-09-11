<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Exemption;
use App\Models\User;

class ExemptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewCalendar);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageCalendar);
    }

    public function update(User $user, Exemption $exemption): bool
    {
        return $user->allows(Permission::ManageCalendar);
    }

    public function delete(User $user, Exemption $exemption): bool
    {
        return $user->allows(Permission::ManageCalendar);
    }
}

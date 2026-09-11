<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Suspension;
use App\Models\User;

class SuspensionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewCalendar);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageCalendar);
    }

    public function update(User $user, Suspension $suspension): bool
    {
        return $user->allows(Permission::ManageCalendar);
    }

    public function delete(User $user, Suspension $suspension): bool
    {
        return $user->allows(Permission::ManageCalendar);
    }
}

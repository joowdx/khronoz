<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Overtime;
use App\Models\User;

class OvertimePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewCalendar);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageCalendar);
    }

    public function update(User $user, Overtime $overtime): bool
    {
        return $user->allows(Permission::ManageCalendar);
    }

    public function delete(User $user, Overtime $overtime): bool
    {
        return $user->allows(Permission::ManageCalendar);
    }
}

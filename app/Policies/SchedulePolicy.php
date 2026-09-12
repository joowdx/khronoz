<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Schedule;
use App\Models\User;

class SchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewScheduling);
    }

    public function view(User $user, Schedule $schedule): bool
    {
        return $user->allows(Permission::ViewScheduling);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }

    public function update(User $user, Schedule $schedule): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }
}

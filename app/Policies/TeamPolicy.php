<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewScheduling);
    }

    public function view(User $user, Team $team): bool
    {
        return $user->allows(Permission::ViewScheduling);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }

    public function update(User $user, Team $team): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }
}

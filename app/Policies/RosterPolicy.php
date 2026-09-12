<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Roster;
use App\Models\User;

class RosterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewScheduling);
    }

    public function view(User $user, Roster $roster): bool
    {
        return $user->allows(Permission::ViewScheduling);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }

    public function update(User $user, Roster $roster): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }

    public function delete(User $user, Roster $roster): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }
}

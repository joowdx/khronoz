<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Terminal;
use App\Models\User;

class TerminalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewTerminals);
    }

    public function view(User $user, Terminal $terminal): bool
    {
        return $user->allows(Permission::ViewTerminals);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageTerminals);
    }

    public function update(User $user, Terminal $terminal): bool
    {
        return $user->allows(Permission::ManageTerminals);
    }

    public function delete(User $user, Terminal $terminal): bool
    {
        return $user->allows(Permission::ManageTerminals);
    }
}

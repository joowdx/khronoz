<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Terminal;
use App\Models\User;

/**
 * Gate::before (AppServiceProvider::configureAuthorization) already answers
 * true for a platform user before any of these run, mirroring WorkgroupPolicy —
 * this class describes non-platform staff only.
 *
 * The split between the two permissions is not "read" versus "write": it is
 * **who may change what a punch means**. Registering a terminal decides which
 * device the importer will accept a file from (decision 44) and which code a
 * file must name, so `terminals.manage` is the right to admit evidence, not
 * merely to edit a row.
 */
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

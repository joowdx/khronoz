<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Ledger;
use App\Models\User;

class LedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewLedgers);
    }

    public function view(User $user, Ledger $ledger): bool
    {
        return $user->allows(Permission::ViewLedgers);
    }

    public function lock(User $user, Ledger $ledger): bool
    {
        return $user->allows(Permission::ManageLedgers);
    }

    public function unlock(User $user, Ledger $ledger): bool
    {
        return $user->allows(Permission::ManageLedgers);
    }
}

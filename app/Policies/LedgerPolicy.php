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
        return $user->agency_id === $ledger->agency_id && ($user->allows(Permission::ViewLedgers)
            || $user->employee_id === $ledger->employee_id
            || collect($ledger->signers)->contains(fn (array $signer): bool => in_array($user->id, $signer['user_ids'], true)));
    }

    public function lock(User $user, Ledger $ledger): bool
    {
        return $user->agency_id === $ledger->agency_id && $user->allows(Permission::ManageLedgers);
    }

    public function unlock(User $user, Ledger $ledger): bool
    {
        return $user->agency_id === $ledger->agency_id && $user->allows(Permission::ManageLedgers);
    }

    public function attest(User $user, Ledger $ledger): bool
    {
        if ($user->agency_id !== $ledger->agency_id || ! $ledger->locked()) {
            return false;
        }

        $next = $ledger->signers[$ledger->attestations()->whereNull('withdrawn_at')->count()] ?? null;

        return $next !== null && in_array($user->id, $next['user_ids'], true);
    }
}

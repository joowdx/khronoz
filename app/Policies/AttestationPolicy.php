<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Attestation;
use App\Models\User;

class AttestationPolicy
{
    public function withdraw(User $user, Attestation $attestation): bool
    {
        return $user->agency_id === $attestation->agency_id
            && $attestation->withdrawn_at === null
            && ($user->id === $attestation->user_id || $user->allows(Permission::ManageLedgers))
            && $attestation->ledger->attestations()->whereNull('withdrawn_at')->orderByDesc('sequence')->value('id') === $attestation->id;
    }
}

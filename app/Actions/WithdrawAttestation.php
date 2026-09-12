<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Attestation;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class WithdrawAttestation
{
    public function handle(Attestation $attestation, User $actor): Attestation
    {
        if ($actor->id !== $attestation->user_id) {
            Gate::forUser($actor)->authorize(Permission::ManageLedgers->value);
        }

        return DB::transaction(function () use ($attestation, $actor): Attestation {
            $ledger = Ledger::query()->lockForUpdate()->findOrFail($attestation->ledger_id);
            $attestation = $ledger->attestations()->findOrFail($attestation->id);
            $latest = $ledger->attestations()->whereNull('withdrawn_at')->orderByDesc('sequence')->first();
            if ($attestation->withdrawn_at !== null || $latest?->id !== $attestation->id) {
                throw ValidationException::withMessages(['attestation' => 'Withdraw only the latest active attestation.']);
            }
            $attestation->update(['withdrawn_at' => now(), 'withdrawn_by' => $actor->id]);
            $ledger->renditions()->whereNull('superseded_at')->update(['superseded_at' => now()]);

            return $attestation;
        });
    }
}

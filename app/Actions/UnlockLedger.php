<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UnlockLedger
{
    public function handle(Ledger $ledger, User $actor): Ledger
    {
        Gate::forUser($actor)->authorize(Permission::ManageLedgers->value);

        return DB::transaction(function () use ($ledger, $actor): Ledger {
            $ledger = Ledger::query()->lockForUpdate()->findOrFail($ledger->id);
            if (! $ledger->locked() || $ledger->attestations()->whereNull('withdrawn_at')->exists()) {
                throw ValidationException::withMessages(['ledger' => 'Withdraw all active attestations before unlocking a locked ledger.']);
            }
            $ledger->update(['unlocked_at' => now(), 'unlocked_by' => $actor->id]);

            return $ledger;
        });
    }
}

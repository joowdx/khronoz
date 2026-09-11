<?php

namespace App\Actions;

use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

final class LockLedger
{
    /**
     * Freeze the month. `ledgers_lock_complete` is what actually refuses an
     * incomplete one; this transaction exists so that P0001 is recoverable
     * (the controller translates it) rather than aborting the surrounding
     * connection. A lock records when, not who (decision 74).
     *
     * No guard here against locking a locked month either: the same trigger
     * refuses that too (decision 84), because a second lock is a re-dating
     * of the first and an attestation seals the ledger as it was at a
     * moment.
     */
    public function handle(Ledger $ledger): void
    {
        DB::transaction(fn () => $ledger->update(['locked_at' => now()]));
    }
}

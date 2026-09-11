<?php

namespace App\Actions;

use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

final class UnlockLedger
{
    /**
     * Reopen the month. `ledgers_unlock_clean` refuses a signed ledger; the
     * nested transaction is what lets the controller translate that P0001
     * without the next query answering 25P02.
     */
    public function handle(Ledger $ledger): void
    {
        DB::transaction(fn () => $ledger->update(['locked_at' => null]));
    }
}

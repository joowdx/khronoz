<?php

namespace App\Actions;

use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

final class LockLedger
{
    public function handle(Ledger $ledger): void
    {
        DB::transaction(fn () => $ledger->update(['locked_at' => now()]));
    }
}

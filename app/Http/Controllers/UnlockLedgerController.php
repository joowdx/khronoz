<?php

namespace App\Http\Controllers;

use App\Actions\UnlockLedger;
use App\Http\Requests\UnlockLedgerRequest;
use App\Models\Ledger;
use Illuminate\Http\RedirectResponse;

class UnlockLedgerController extends Controller
{
    public function __invoke(UnlockLedgerRequest $request, Ledger $ledger, UnlockLedger $unlock): RedirectResponse
    {
        $unlock->handle($ledger, $request->user());

        return redirect()->route('ledgers.show', $ledger)->with('success', 'Ledger unlocked.');
    }
}

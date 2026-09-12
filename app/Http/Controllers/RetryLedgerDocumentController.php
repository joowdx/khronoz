<?php

namespace App\Http\Controllers;

use App\Actions\RetryLedgerDocument;
use App\Http\Requests\RetryLedgerDocumentRequest;
use App\Models\Ledger;
use App\Models\Rendition;
use Illuminate\Http\RedirectResponse;

class RetryLedgerDocumentController extends Controller
{
    public function __invoke(RetryLedgerDocumentRequest $request, Ledger $ledger, Rendition $rendition, RetryLedgerDocument $retry): RedirectResponse
    {
        $retry->handle($rendition, $request->user());

        return redirect()->route('ledgers.show', $ledger)->with('success', 'PDF generation queued.');
    }
}

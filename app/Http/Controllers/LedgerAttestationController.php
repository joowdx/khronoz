<?php

namespace App\Http\Controllers;

use App\Actions\AttestLedger;
use App\Actions\WithdrawAttestation;
use App\Http\Requests\AttestLedgerRequest;
use App\Http\Requests\WithdrawAttestationRequest;
use App\Models\Attestation;
use App\Models\Ledger;
use Illuminate\Http\RedirectResponse;

class LedgerAttestationController extends Controller
{
    public function store(AttestLedgerRequest $request, Ledger $ledger, AttestLedger $attest): RedirectResponse
    {
        $attest->handle($ledger, $request->user(), $request->validated('role'));

        return redirect()->route('ledgers.show', $ledger)->with('success', 'Attestation recorded.');
    }

    public function destroy(WithdrawAttestationRequest $request, Ledger $ledger, Attestation $attestation, WithdrawAttestation $withdraw): RedirectResponse
    {
        $withdraw->handle($attestation, $request->user());

        return redirect()->route('ledgers.show', $ledger)->with('success', 'Attestation withdrawn.');
    }
}

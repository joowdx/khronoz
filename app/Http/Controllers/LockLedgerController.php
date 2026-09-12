<?php

namespace App\Http\Controllers;

use App\Actions\LockLedger;
use App\Enums\Work;
use App\Http\Requests\LockLedgerRequest;
use App\Models\Cadence;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;

class LockLedgerController extends Controller
{
    public function __invoke(LockLedgerRequest $request, LockLedger $lock): RedirectResponse
    {
        $ledger = $lock->handle(
            Employee::withTrashed()->findOrFail($request->validated('employee_id')),
            $request->validated('starts'), $request->validated('ends'),
            Work::from($request->validated('scope')), $request->user(),
            $request->filled('cadence_id') ? Cadence::findOrFail($request->validated('cadence_id')) : null,
        );

        return redirect()->route('ledgers.show', $ledger)->with('success', 'Ledger locked.');
    }
}

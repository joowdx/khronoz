<?php

namespace App\Http\Controllers;

use App\Attendance\LedgerPolicyResolver;
use App\Attendance\LedgerSnapshot;
use App\Enums\Period;
use App\Enums\RenditionStatus;
use App\Enums\Work;
use App\Http\Requests\DownloadLedgerRequest;
use App\Models\Employee;
use App\Models\Ledger;
use App\Support\DocumentStorage;
use App\Support\LedgerPdf;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class LedgerPdfController extends Controller
{
    public function show(DownloadLedgerRequest $request, Ledger $ledger, LedgerSnapshot $snapshots, LedgerPdf $pdf, DocumentStorage $storage): Response
    {
        if ($request->filled('starts') && $request->input('starts') !== $ledger->starts->toDateString()
            || $request->filled('ends') && $request->input('ends') !== $ledger->ends->toDateString()
            || $request->filled('period') && $request->input('period') !== Period::Full->value
            || $request->filled('work') && $request->input('work') !== $ledger->scope->value) {
            throw ValidationException::withMessages(['form' => 'Use a current employee download to change the range or work filter.']);
        }
        $rendition = $ledger->renditions()->whereNull('superseded_at')->latest('requested_at')->first();
        $snapshot = $rendition?->snapshot ?? $snapshots->forRendition($ledger);
        $snapshot['document'] = ['generated_at' => now()->toIso8601String()];
        $bytes = $rendition?->status === RenditionStatus::Ready && $rendition->document_id !== null
            ? $storage->read($rendition->document)
            : $pdf->render($snapshot, $snapshot['policy']['template'] ?? 'form48', $rendition?->token);

        return response($bytes)->withHeaders([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ledger-'.$ledger->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function current(DownloadLedgerRequest $request, Employee $employee, LedgerSnapshot $snapshots, LedgerPolicyResolver $policies, LedgerPdf $pdf): Response
    {
        $ledger = new Ledger([
            'agency_id' => $employee->agency_id, 'employee_id' => $employee->id,
            'starts' => $request->validated('starts'), 'ends' => $request->validated('ends'),
            'scope' => Work::tryFrom($request->validated('work', 'all')) ?? Work::All, 'revision' => 0,
        ]);
        $period = Period::tryFrom($request->validated('period', 'full')) ?? Period::Full;
        if ($period !== Period::Full) {
            $month = $ledger->starts->startOfMonth();
            $ledger->starts = $ledger->starts->max($period === Period::First ? $month : $month->setDay(16));
            $ledger->ends = $ledger->ends->min($period === Period::First ? $month->setDay(15) : $month->endOfMonth());
            if ($ledger->ends->lt($ledger->starts)) {
                throw ValidationException::withMessages(['period' => 'This period does not intersect the selected range.']);
            }
        }
        $captured = $snapshots->capture($ledger);
        $policy = $policies->resolve($employee, $ledger->ends);
        $snapshot = [
            'version' => 1,
            'ledger' => ['id' => null, 'starts' => $ledger->starts->toDateString(), 'ends' => $ledger->ends->toDateString(), 'scope' => $ledger->scope->value, 'revision' => 0, 'locked_at' => null],
            ...$captured['identity'], ...$captured['calculation'], 'policy' => $policy,
            'signers' => [], 'attestations' => [], 'document' => ['generated_at' => now()->toIso8601String()],
        ];

        return response($pdf->render($snapshot, $policy['template']))->withHeaders([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ledger-'.$employee->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

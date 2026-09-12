<?php

namespace App\Http\Controllers;

use App\Enums\RenditionStatus;
use App\Models\Ledger;
use App\Models\Rendition;
use App\Support\DocumentStorage;
use App\Support\LedgerPdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RenditionPdfController extends Controller
{
    public function show(Ledger $ledger, Rendition $rendition, LedgerPdf $pdf, DocumentStorage $storage): Response
    {
        Gate::authorize('download', $rendition);
        $snapshot = $rendition->snapshot;
        $snapshot['document'] = ['generated_at' => now()->toIso8601String()];
        $bytes = $rendition->status === RenditionStatus::Ready && $rendition->document_id !== null
            ? $storage->read($rendition->document)
            : $pdf->render($snapshot, $rendition->template, $rendition->token);

        return response($bytes)->withHeaders([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="rendition-'.$rendition->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

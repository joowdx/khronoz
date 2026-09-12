<?php

namespace Tests\Feature\Support;

use App\Support\LedgerPdf;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

class LedgerPdfTest extends TestCase
{
    public function test_form48_uses_legal_paper(): void
    {
        $pdf = Pdf::fake();

        app(LedgerPdf::class)->render([
            'ledger' => ['starts' => '2026-09-01', 'ends' => '2026-09-30'],
        ], 'form48');

        $this->assertSame(['width' => 8.5, 'height' => 14.0, 'unit' => 'in'], $pdf->paperSize);
    }

    public function test_plain_uses_a4_paper(): void
    {
        $pdf = Pdf::fake();

        app(LedgerPdf::class)->render([], 'plain');

        $this->assertSame(['width' => 210.0, 'height' => 297.0, 'unit' => 'mm'], $pdf->paperSize);
    }
}

<?php

namespace Tests\Feature\Views;

use Carbon\CarbonImmutable;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Tests\TestCase;

class LedgerPdfViewTest extends TestCase
{
    public function test_completed_form48_renders_each_calendar_month_and_verifies_each_page(): void
    {
        $snapshot = [
            'ledger' => ['starts' => '2026-01-30', 'ends' => '2026-02-02'],
            'employee' => ['name' => 'Ana Example'],
            'workdays' => [[
                'date' => '2026-01-30',
                'undertime' => 75,
                'punches' => [
                    ['slot' => 1, 'kind' => ['value' => 'in'], 'actual_at' => '2026-01-30T00:01:00Z'],
                    ['slot' => 2, 'kind' => ['value' => 'out'], 'actual_at' => '2026-01-30T09:02:00Z'],
                ],
            ]],
        ];
        config(['app.timezone' => 'Asia/Manila']);
        $verificationUrl = 'https://example.test/verify/opaque-token';
        $qrSvg = (new SvgWriter)->write(new QrCode(data: $verificationUrl))->getString();

        $html = view('pdf.ledgers.form48', compact('snapshot', 'verificationUrl', 'qrSvg') + ['preview' => false])->render();

        $this->assertSame(2, substr_count($html, 'class="page form48"'));
        $this->assertSame(2, substr_count($html, 'alt="Verify attested ledger"'));
        $this->assertSame(55, substr_count($html, 'Outside ledger period'));
        $this->assertStringContainsString('January 2026', $html);
        $this->assertStringContainsString('February 2026', $html);
        $this->assertStringContainsString('08:01', $html);
        $this->assertStringContainsString('17:02', $html);
        $this->assertStringNotContainsString('PREVIEW', $html);
    }

    public function test_preview_escapes_personal_data_and_never_includes_a_verification_qr(): void
    {
        $snapshot = [
            'ledger' => ['starts' => '2026-09-01', 'ends' => '2026-09-30'],
            'employee' => ['name' => '<script>alert("name")</script>'],
            'attestations' => [['name' => '<img src="https://example.test/track">']],
        ];

        $html = view('pdf.ledgers.form48', [
            'snapshot' => $snapshot,
            'preview' => true,
            'verificationUrl' => 'https://example.test/verify/should-not-appear',
            'qrSvg' => '<svg></svg>',
        ])->render();

        $this->assertStringContainsString('PREVIEW — NOT ATTESTED', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img ', $html);
        $this->assertStringNotContainsString('should-not-appear', $html);
    }

    public function test_transient_filters_are_disclosed_without_changing_the_totals_scope(): void
    {
        $snapshot = [
            'ledger' => ['starts' => '2026-09-01', 'ends' => '2026-09-30'],
            'filters' => [['value' => 'night', 'label' => 'Night work']],
        ];

        $html = view('pdf.ledgers.form48', [
            'snapshot' => $snapshot,
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        $this->assertStringContainsString('Detail filter: Night work', $html);
        $this->assertStringContainsString('Totals remain for the complete selected range.', $html);
    }

    public function test_plain_form_paginates_without_losing_the_last_day(): void
    {
        $snapshot = ['ledger' => ['starts' => '2026-09-01', 'ends' => '2026-09-26'], 'workdays' => []];
        for ($day = 1; $day <= 26; $day++) {
            $snapshot['workdays'][] = ['date' => CarbonImmutable::create(2026, 9, $day)->format('Y-m-d')];
        }

        $html = view('pdf.ledgers.plain', [
            'snapshot' => $snapshot,
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        $this->assertSame(2, substr_count($html, 'class="page plain"'));
        $this->assertStringContainsString('2026-09-26', $html);
        $this->assertStringNotContainsString('<img ', $html);
    }

    public function test_empty_plain_form_still_renders_one_preview_page(): void
    {
        $html = view('pdf.ledgers.plain', [
            'snapshot' => ['ledger' => ['starts' => '2026-09-01', 'ends' => '2026-08-31']],
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        $this->assertSame(1, substr_count($html, 'class="page plain"'));
        $this->assertStringContainsString('No attestations recorded.', $html);
    }

    public function test_extra_form48_slots_and_overnight_times_are_preserved(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $snapshot = [
            'ledger' => ['starts' => '2026-09-01', 'ends' => '2026-09-30'],
            'workdays' => [[
                'date' => '2026-09-01',
                'status' => ['label' => 'Off'],
                'premium' => ['value' => 'rest', 'label' => 'Rest day'],
                'excess' => 90,
                'night' => 60,
                'night_excess' => 30,
                'punches' => [
                    ['slot' => 2, 'kind' => ['value' => 'out'], 'actual_at' => '2026-09-02 01:15:00'],
                    ['slot' => 3, 'kind' => ['value' => 'in'], 'actual_at' => '2026-09-02 03:05:00'],
                    ['slot' => 3, 'kind' => ['value' => 'out'], 'actual_at' => '2026-09-02 05:45:00'],
                    ['slot' => 4, 'kind' => ['value' => 'in'], 'actual_at' => '2026-09-02 06:30:00'],
                ],
            ]],
        ];

        $html = view('pdf.ledgers.form48', [
            'snapshot' => $snapshot,
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        $this->assertStringContainsString('01:15<small>(+1d)</small>', $html);
        $this->assertStringContainsString('03:05<small>(+1d)</small>', $html);
        $this->assertStringContainsString('05:45<small>(+1d)</small>', $html);
        $this->assertStringContainsString('06:30<small>(+1d)</small>', $html);
        $this->assertStringContainsString('Rest day', $html);
        $this->assertStringContainsString('Excess 90 min', $html);
        $this->assertStringContainsString('Night 60 min', $html);
        $this->assertStringContainsString('Night excess 30 min', $html);
    }
}

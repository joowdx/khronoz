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
        $this->assertSame(55, substr_count($html, 'Outside covered range'));
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

        $this->assertStringContainsString('PREVIEW - NOT ATTESTED', $html);
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

        $this->assertStringContainsString('Detail filter:</strong> Night work', $html);
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
        $this->assertStringContainsString('<strong>26</strong><span>Sat</span>', $html);
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
        $this->assertStringContainsString('Excess 01:30', $html);
        $this->assertStringContainsString('Night 01:00', $html);
        $this->assertStringContainsString('Night excess 00:30', $html);
    }

    public function test_form48_uses_the_two_level_clock_header_and_separate_hhmm_deductions(): void
    {
        $snapshot = [
            'ledger' => ['starts' => '2026-09-01', 'ends' => '2026-09-30'],
            'workdays' => [[
                'date' => '2026-09-01',
                'tardy' => 7,
                'undertime' => 65,
            ]],
        ];

        $html = view('pdf.ledgers.form48', [
            'snapshot' => $snapshot,
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        $this->assertStringContainsString('rowspan="2" class="day"', $html);
        $this->assertStringContainsString('colspan="2">AM', $html);
        $this->assertStringContainsString('colspan="2">PM', $html);
        $this->assertStringContainsString('Tardiness', $html);
        $this->assertStringContainsString('Undertime', $html);
        $this->assertStringContainsString('Adjustment', $html);
        $this->assertStringContainsString('00:07', $html);
        $this->assertStringContainsString('01:05', $html);
    }

    public function test_plain_form_shows_frozen_duty_times_and_vertical_attestations(): void
    {
        $snapshot = [
            'ledger' => ['starts' => '2026-09-01', 'ends' => '2026-09-01'],
            'workdays' => [[
                'date' => '2026-09-01',
                'shift_name' => 'Standard duty',
                'punches' => [
                    ['slot' => 1, 'kind' => ['value' => 'in'], 'expected_at' => '2026-09-01 08:00:00', 'actual_at' => '2026-09-01 08:03:00'],
                    ['slot' => 1, 'kind' => ['value' => 'out'], 'expected_at' => '2026-09-01 12:00:00', 'actual_at' => '2026-09-01 12:00:00'],
                    ['slot' => 2, 'kind' => ['value' => 'in'], 'expected_at' => '2026-09-01 13:00:00', 'actual_at' => '2026-09-01 13:00:00'],
                    ['slot' => 2, 'kind' => ['value' => 'out'], 'expected_at' => '2026-09-01 17:00:00', 'actual_at' => '2026-09-01 17:02:00'],
                ],
            ]],
            'attestations' => [[
                'role' => 'employee',
                'name' => 'Ana Example',
                'position' => 'Administrative Officer II',
                'sequence' => 1,
                'at' => '2026-09-02T01:00:00Z',
            ]],
        ];

        $html = view('pdf.ledgers.plain', [
            'snapshot' => $snapshot,
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        $this->assertStringContainsString('Standard duty 08:00-12:00 / 13:00-17:00', $html);
        $this->assertStringContainsString('class="attestation-step"', $html);
        $this->assertStringContainsString('Administrative Officer II', $html);
        $this->assertStringContainsString('Sep 2, 2026 09:00', $html);
    }
}

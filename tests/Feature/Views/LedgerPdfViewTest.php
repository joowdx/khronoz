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
        $this->assertSame(2, substr_count($html, 'alt="Verify this ledger"'));
        $this->assertSame(66, substr_count($html, 'class="outside continuous-outside"') + substr_count($html, 'class="outside"'));
        $this->assertStringContainsString('January 2026', $html);
        $this->assertStringContainsString('February 2026', $html);
        $this->assertStringContainsString('08:01', $html);
        $this->assertStringContainsString('17:02', $html);
        $this->assertStringNotContainsString('PREVIEW', $html);
    }

    public function test_transient_form48_escapes_personal_data_without_a_status_watermark_or_verification_qr(): void
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

        $this->assertStringNotContainsString('PREVIEW', $html);
        $this->assertStringNotContainsString('NOT ATTESTED', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img ', $html);
        $this->assertStringNotContainsString('should-not-appear', $html);
        $this->assertStringNotContainsString('Employee no.', $html);
        $this->assertStringNotContainsString('24-hour time', $html);
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
        $this->assertMatchesRegularExpression('/<strong>26<\\/strong>\\s*<span>Sat<\\/span>/', $html);
        $this->assertSame(2, substr_count($html, '<p class="certification">'));
        $this->assertSame(2, substr_count($html, 'class="verification-brand"'));
        $this->assertStringNotContainsString('class="endorsements"', $html);
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
        $this->assertStringNotContainsString('No attestations recorded.', $html);
        $this->assertStringNotContainsString('class="endorsements"', $html);
        $this->assertStringContainsString('<p class="certification">', $html);
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

        $this->assertStringContainsString('01:15', $html);
        $this->assertStringContainsString('03:05', $html);
        $this->assertStringContainsString('05:45', $html);
        $this->assertStringContainsString('06:30', $html);
        $this->assertStringNotContainsString('(+1d)', $html);
        $this->assertStringContainsString('Rest day', $html);
        $this->assertStringContainsString('Excess 01:30', $html);
        $this->assertStringContainsString('Night 01:00', $html);
        $this->assertStringContainsString('Night excess 00:30', $html);
    }

    public function test_form48_uses_month_buffers_and_places_punches_on_their_calendar_rows(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $snapshot = [
            'ledger' => ['starts' => '2026-07-01', 'ends' => '2026-07-31'],
            'boundaries' => [[
                'date' => '2026-06-30',
                'punches' => [
                    ['slot' => 1, 'kind' => ['value' => 'in'], 'expected_at' => '2026-06-30T19:00:00+08:00', 'actual_at' => '2026-06-30T19:00:00+08:00'],
                    ['slot' => 1, 'kind' => ['value' => 'out'], 'expected_at' => '2026-07-01T07:00:00+08:00', 'actual_at' => '2026-07-01T07:00:00+08:00'],
                ],
            ]],
            'workdays' => [
                [
                    'date' => '2026-07-05',
                    'punches' => [
                        ['slot' => 1, 'kind' => ['value' => 'in'], 'expected_at' => '2026-07-05T16:00:00+08:00', 'actual_at' => '2026-07-05T16:00:00+08:00'],
                        ['slot' => 1, 'kind' => ['value' => 'out'], 'expected_at' => '2026-07-06T00:00:00+08:00', 'actual_at' => '2026-07-06T00:00:00+08:00'],
                    ],
                ],
                [
                    'date' => '2026-07-10',
                    'punches' => [
                        ['slot' => 1, 'kind' => ['value' => 'in'], 'expected_at' => '2026-07-10T00:00:00+08:00', 'actual_at' => '2026-07-10T00:00:00+08:00'],
                        ['slot' => 1, 'kind' => ['value' => 'out'], 'expected_at' => '2026-07-10T08:00:00+08:00', 'actual_at' => '2026-07-10T08:00:00+08:00'],
                    ],
                ],
                [
                    'date' => '2026-07-12',
                    'punches' => [
                        ['slot' => 1, 'kind' => ['value' => 'in'], 'expected_at' => '2026-07-12T19:00:00+08:00', 'actual_at' => '2026-07-12T19:00:00+08:00'],
                        ['slot' => 1, 'kind' => ['value' => 'out'], 'expected_at' => '2026-07-13T07:00:00+08:00', 'actual_at' => '2026-07-13T07:00:00+08:00'],
                    ],
                ],
                [
                    'date' => '2026-07-31',
                    'punches' => [
                        ['slot' => 1, 'kind' => ['value' => 'in'], 'expected_at' => '2026-07-31T19:00:00+08:00', 'actual_at' => '2026-07-31T19:00:00+08:00'],
                        ['slot' => 1, 'kind' => ['value' => 'out'], 'expected_at' => '2026-08-01T07:00:00+08:00', 'actual_at' => '2026-08-01T07:00:00+08:00'],
                    ],
                ],
            ],
        ];

        $html = view('pdf.ledgers.form48', [
            'snapshot' => $snapshot,
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        preg_match('/<tbody>(.*?)<\\/tbody>/s', $html, $body);
        preg_match('/<tr[^>]*><td class="day-cell">5<\\/td>(.*?)<\\/tr>/s', $html, $dayFive);
        preg_match('/<tr[^>]*><td class="day-cell">6<\\/td>(.*?)<\\/tr>/s', $html, $daySix);
        preg_match('/<tr[^>]*><td class="day-cell">10<\\/td>(.*?)<\\/tr>/s', $html, $dayTen);
        preg_match('/<tr[^>]*><td class="day-cell">12<\\/td>(.*?)<\\/tr>/s', $html, $dayTwelve);
        preg_match('/<tr[^>]*><td class="day-cell">13<\\/td>(.*?)<\\/tr>/s', $html, $dayThirteen);

        $this->assertArrayHasKey(1, $body);
        $this->assertSame(36, substr_count($body[1], '<tr'));
        $this->assertStringNotContainsString('>--<', $body[1]);
        $this->assertSame(3, substr_count($body[1], '<tr class="outside continuous-outside"><td colspan="11" class="outside-scope"></td></tr>'));
        $this->assertMatchesRegularExpression('/<tr class="boundary-context"><td class="day-cell boundary-day">Jun 30<\\/td>.*class="time-cell context-time"[^>]*>19:00.*<td class="time-cell context-time">07:00<\\/td>/s', $html);
        $this->assertMatchesRegularExpression('/<tr class="boundary-context"><td class="day-cell boundary-day">Aug 1<\\/td>.*class="time-cell trailing-timeout"[^>]*>07:00/s', $html);
        $this->assertStringContainsString('16:00', $dayFive[1]);
        $this->assertStringContainsString('00:00<small>(+1d)</small>', $dayFive[1]);
        $this->assertStringNotContainsString('00:00', $daySix[1]);
        $this->assertLessThan(strpos($dayTen[1], '08:00'), strpos($dayTen[1], '00:00'));
        $this->assertStringContainsString('19:00', $dayTwelve[1]);
        $this->assertStringNotContainsString('07:00', $dayTwelve[1]);
        $this->assertStringContainsString('07:00', $dayThirteen[1]);
    }

    public function test_form48_uses_the_two_level_clock_header_and_separate_hhmm_deductions(): void
    {
        $snapshot = [
            'ledger' => ['starts' => '2026-09-01', 'ends' => '2026-09-30'],
            'workdays' => [[
                'date' => '2026-09-01',
                'status' => ['value' => 'present', 'label' => 'Present'],
                'tardy' => 7,
                'undertime' => 65,
                'worked' => 408,
                'punches' => [
                    ['slot' => 1, 'kind' => ['value' => 'in'], 'actual_at' => '2026-09-01T08:07:00+08:00'],
                    ['slot' => 1, 'kind' => ['value' => 'out'], 'actual_at' => '2026-09-01T12:00:00+08:00'],
                    ['slot' => 2, 'kind' => ['value' => 'in'], 'actual_at' => '2026-09-01T13:00:00+08:00'],
                    ['slot' => 2, 'kind' => ['value' => 'out'], 'actual_at' => '2026-09-01T15:55:00+08:00'],
                ],
            ]],
            'totals' => ['worked' => 408, 'tardy' => 7, 'undertime' => 65, 'overtime' => 90, 'overtimeByDate' => ['2026-09-01' => 90]],
        ];

        $html = view('pdf.ledgers.form48', [
            'snapshot' => $snapshot,
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        $colgroupStart = strpos($html, '<colgroup>');
        $colgroupEnd = strpos($html, '</colgroup>');
        $this->assertNotFalse($colgroupStart);
        $this->assertNotFalse($colgroupEnd);
        $colgroup = substr($html, $colgroupStart, $colgroupEnd - $colgroupStart);

        $this->assertMatchesRegularExpression('/<col[^>]*class="[^"]*day-col[^"]*"[^>]*>/', $colgroup);
        $this->assertMatchesRegularExpression('/<col[^>]*class="[^"]*remarks-col[^"]*"[^>]*>/', $colgroup);
        $this->assertSame(1, preg_match_all('/<col[^>]*class="[^"]*day-col[^"]*"[^>]*>/', $colgroup));
        $this->assertSame(1, preg_match_all('/<col[^>]*class="[^"]*remarks-col[^"]*"[^>]*>/', $colgroup));
        $this->assertSame(9, preg_match_all('/<col[^>]*class="[^"]*numeric-col[^"]*"[^>]*>/', $colgroup));
        $this->assertLessThan(strpos($colgroup, 'class="remarks-col"'), strrpos($colgroup, 'class="numeric-col"'));

        $this->assertMatchesRegularExpression(
            '/<tr>\\s*<th rowspan="2" class="day">DAY<\\/th>\\s*<th colspan="2">AM<\\/th>\\s*<th colspan="2">PM<\\/th>\\s*<th colspan="3">DEFICIT<\\/th>\\s*<th rowspan="2" class="hours-col">\\s*<span>HOURS<\\/span>\\s*<span>WORKED<\\/span><\\/th>\\s*<th rowspan="2" class="hours-col">\\s*<span>OVERTIME<\\/span>\\s*<span>HOURS<\\/span><\\/th>\\s*<th rowspan="2" class="remarks-col">\\s*<span>REMARKS<\\/span>\\s*<span>ADJUSTMENTS<\\/span><\\/th>\\s*<\\/tr>/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<tr>\\s*<th>IN<\\/th>\\s*<th>OUT<\\/th>\\s*<th>IN<\\/th>\\s*<th>OUT<\\/th>\\s*<th class="metric-col">TARDINESS<\\/th>\\s*<th class="metric-col">UNDERTIME<\\/th>\\s*<th class="metric-col">TOTAL<\\/th>\\s*<\\/tr>/s',
            $html
        );
        $this->assertStringContainsString('rowspan="2" class="day"', $html);
        $this->assertStringContainsString('colspan="2">AM', $html);
        $this->assertStringContainsString('colspan="2">PM', $html);
        $this->assertStringContainsString('colspan="3">DEFICIT', $html);
        $this->assertStringContainsString('<span>HOURS</span><span>WORKED</span>', $html);
        $this->assertStringContainsString('<span>OVERTIME</span><span>HOURS</span>', $html);
        $this->assertStringContainsString('<span>REMARKS</span><span>ADJUSTMENTS</span>', $html);
        $this->assertStringContainsString('TARDINESS', $html);
        $this->assertStringContainsString('UNDERTIME', $html);
        $this->assertStringContainsString('TOTAL', $html);
        $this->assertStringNotContainsString('HH:MM', $html);
        $this->assertStringContainsString('class="metric-cell">00:07', $html);
        $this->assertStringContainsString('class="metric-cell">01:05', $html);
        $this->assertStringContainsString('class="metric-cell">01:12', $html);
        $this->assertMatchesRegularExpression('/<td>01:12<\\/td>/', $html);
        $this->assertStringContainsString('00:07', $html);
        $this->assertStringContainsString('01:05', $html);
        $this->assertStringContainsString('01:12', $html);
        $this->assertStringContainsString('class="metric-cell">01:30', $html);
        $this->assertStringContainsString('<span>Net</span><span>Deficit</span>', $html);
        $this->assertStringContainsString('<span>Approved</span><span>Overtime</span>', $html);

        $css = file_get_contents(resource_path('css/ledger-pdf.css'));
        $this->assertNotFalse($css);
        $this->assertStringContainsString('.form48 {', $css);
        $this->assertStringContainsString('--accent: #111827;', $css);
        $this->assertStringContainsString('.form48-header {', $css);
        $this->assertStringContainsString('.form48-employee-name {', $css);
        $this->assertStringContainsString('.form48-identity-line {', $css);
        $this->assertStringContainsString('.form48-duty-lines {', $css);
        $this->assertStringContainsString('.form48-summary {', $css);
        $this->assertMatchesRegularExpression('/\.form48\s+\.attendance/', $css);
        $this->assertStringContainsString('border-radius: 0;', $css);
        $this->assertStringContainsString('.form48-table col.day-col { width: 0.48in; }', $css);
        $this->assertStringContainsString('.form48-table col.numeric-col { width: 0.58in; }', $css);
        $this->assertStringContainsString('.form48-table col.remarks-col { width: 2in; }', $css);
        $this->assertStringContainsString('.form48-table .day, .form48-table .day-cell { width: 0.48in; padding-right: 11px; text-align: right; vertical-align: middle; }', $css);
        $this->assertStringContainsString('border-collapse: collapse;', $css);

        $this->assertStringContainsString('Civil Service Form No. 48', $html);
        $this->assertStringContainsString('class="form48-employee-name"', $html);
        $this->assertStringContainsString('class="form48-period-line"', $html);
        $this->assertStringContainsString('class="form48-duty-lines"', $html);
        $this->assertStringContainsString('class="form48-summary"', $html);
        $this->assertStringContainsString('I certify on my honor that the above is a true and correct report', $html);
        $this->assertStringNotContainsString('>Present<', $html);
    }

    public function test_form48_distinguishes_calendar_rows_and_only_merges_unscheduled_days(): void
    {
        $snapshot = [
            'ledger' => ['starts' => '2026-04-02', 'ends' => '2026-04-30'],
            'employee' => ['name' => 'Ana Example'],
            'workdays' => [
                [
                    'date' => '2026-04-03',
                    'holidays' => [[
                        'id' => 'HOLIDAY-1',
                        'name' => 'Good Friday',
                        'type' => ['value' => 'regular', 'label' => 'Regular holiday'],
                    ]],
                    'punches' => [],
                ],
                [
                    'date' => '2026-04-05',
                    'punches' => [
                        ['slot' => 1, 'kind' => ['value' => 'in'], 'actual_at' => '2026-04-05T08:00:00+08:00'],
                        ['slot' => 1, 'kind' => ['value' => 'out'], 'actual_at' => '2026-04-05T12:00:00+08:00'],
                    ],
                ],
                [
                    'date' => '2026-04-09',
                    'holidays' => [[
                        'id' => 'HOLIDAY-2',
                        'name' => 'Founding Anniversary',
                        'type' => ['value' => 'local', 'label' => 'Local holiday'],
                    ]],
                    'punches' => [
                        ['slot' => 1, 'kind' => ['value' => 'in'], 'actual_at' => '2026-04-09T08:00:00+08:00'],
                        ['slot' => 1, 'kind' => ['value' => 'out'], 'actual_at' => '2026-04-09T12:00:00+08:00'],
                    ],
                ],
                [
                    'date' => '2026-04-12',
                    'holidays' => [
                        [
                            'id' => 'HOLIDAY-3',
                            'name' => 'Founders Day',
                            'type' => ['value' => 'regular', 'label' => 'Regular holiday'],
                        ],
                        [
                            'id' => 'HOLIDAY-4',
                            'name' => 'Cityhood Day',
                            'type' => ['value' => 'local', 'label' => 'Local holiday'],
                        ],
                    ],
                    'punches' => [],
                ],
            ],
            'totals' => ['overtimeByDate' => ['2026-04-12' => 480]],
        ];

        $html = view('pdf.ledgers.form48', [
            'snapshot' => $snapshot,
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        preg_match('/<tbody>(.*?)<\\/tbody>/s', $html, $body);
        $this->assertArrayHasKey(1, $body);
        $this->assertSame(35, substr_count($body[1], '<tr'));
        $this->assertSame(5, substr_count($body[1], '<tr class="outside continuous-outside"><td colspan="11" class="outside-scope"></td></tr>'));
        $this->assertMatchesRegularExpression('/<tr class="outside"><td class="day-cell">1<\\/td>\\s*<td colspan="10" class="outside-scope"><\\/td>/', $html);
        $this->assertMatchesRegularExpression('/<tr class="holiday"><td class="day-cell">3<\\/td>\\s*<td colspan="4" class="calendar-label">Good Friday<\\/td>.*<td class="annotations">Regular holiday<\\/td>/s', $html);
        $this->assertMatchesRegularExpression('/<tr class="weekend"><td class="day-cell">4<\\/td>\\s*<td colspan="4" class="calendar-label">Saturday<\\/td>.*<td class="annotations"><\\/td>/s', $html);
        $this->assertMatchesRegularExpression('/<tr class="weekend"><td class="day-cell">5<\\/td>.*08:00.*12:00.*<td class="annotations"><\\/td>\\s*<\\/tr>/s', $html);
        $this->assertMatchesRegularExpression('/<tr class="holiday"><td class="day-cell">9<\\/td>.*08:00.*12:00.*<td class="annotations">Local holiday<\\/td>\\s*<\\/tr>/s', $html);
        $this->assertMatchesRegularExpression('/<tr class="holiday weekend"><td class="day-cell">12<\\/td>\\s*<td colspan="4" class="calendar-label">Sunday • Founders Day • Cityhood Day<\\/td>.*<td class="metric-cell">08:00<\\/td>\\s*<td class="annotations">Regular holiday \\/ Local holiday<\\/td>/s', $html);
        $this->assertMatchesRegularExpression('/<tr class="outside continuous-outside"><td colspan="11" class="outside-scope"><\\/td><\\/tr>/', $html);
        $this->assertStringContainsString('tbody tr.weekend td { background-color: #f0f2f4;', file_get_contents(resource_path('css/ledger-pdf.css')));
        $this->assertStringContainsString('tbody tr.holiday td { background-image: repeating-linear-gradient', file_get_contents(resource_path('css/ledger-pdf.css')));
        $this->assertStringContainsString('.form48-table .calendar-label { padding: 1px 7px; font-size: 6.5pt; font-weight: bold; letter-spacing: 0.025em; text-align: left;', file_get_contents(resource_path('css/ledger-pdf.css')));
        $this->assertStringContainsString('tbody tr.outside td { background-color: #f7f8fa; background-image: radial-gradient', file_get_contents(resource_path('css/ledger-pdf.css')));
    }

    public function test_plain_form_stacks_multiword_headings_and_calculates_deficit_totals(): void
    {
        $snapshot = [
            'ledger' => ['starts' => '2026-09-01', 'ends' => '2026-09-01'],
            'workdays' => [[
                'date' => '2026-09-01',
                'shift_name' => 'Standard duty',
                'tardy' => 7,
                'undertime' => 65,
                'worked' => 500,
                'punches' => [
                    ['slot' => 1, 'kind' => ['value' => 'in'], 'expected_at' => '2026-09-01 08:00:00', 'actual_at' => '2026-09-01 08:03:00'],
                    ['slot' => 1, 'kind' => ['value' => 'out'], 'expected_at' => '2026-09-01 12:00:00', 'actual_at' => '2026-09-01 12:00:00'],
                    ['slot' => 2, 'kind' => ['value' => 'in'], 'expected_at' => '2026-09-01 13:00:00', 'actual_at' => '2026-09-01 13:00:00'],
                    ['slot' => 2, 'kind' => ['value' => 'out'], 'expected_at' => '2026-09-01 17:00:00', 'actual_at' => '2026-09-01 17:02:00'],
                ],
            ]],
            'totals' => ['worked' => 500, 'tardy' => 7, 'undertime' => 65, 'overtimeByDate' => ['2026-09-01' => 90]],
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

        $this->assertSame(1, preg_match('/<tr>\\s*<th rowspan="2" class="plain-date-col"[^>]*>DATE<\\/th>\\s*<th rowspan="2" class="plain-status-col"[^>]*>\\s*<span>STATUS<\\/span>\\s*<span>DUTY<\\/span>\\s*<\\/th>\\s*<th rowspan="2" class="plain-punches-col"[^>]*>\\s*<span>ACTUAL<\\/span>\\s*<span>PUNCHES<\\/span>\\s*<\\/th>/s', $html));
        $this->assertMatchesRegularExpression(
            '/rowspan="2" class="plain-hours-col"[^>]*>\\s*<span>HOURS<\\/span>\\s*<span>WORKED<\\/span>\\s*<\\/th>/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/rowspan="2" class="plain-overtime-col"[^>]*>\\s*<span>OVERTIME<\\/span>\\s*<span>HOURS<\\/span>\\s*<\\/th>/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/rowspan="2" class="plain-notes-col"[^>]*>\\s*<span>REMARKS<\\/span>\\s*<span>ADJUSTMENTS<\\/span>\\s*<\\/th>/s',
            $html
        );
        $this->assertStringNotContainsString('Status / duty', $html);
        $this->assertStringNotContainsString('Actual punches (24-hour)', $html);
        $this->assertStringNotContainsString('Adjustment / remarks', $html);
        $this->assertMatchesRegularExpression('/<th colspan="3">DEFICIT<\\/th>/', $html);
        $this->assertMatchesRegularExpression('/<th class="metric-col">TARDINESS<\\/th>\\s*<th class="metric-col">UNDERTIME<\\/th>\\s*<th class="metric-col">TOTAL<\\/th>/', $html);
        $this->assertStringContainsString('<td class="plain-metric">01:12</td>', $html);
        $this->assertStringContainsString('<td class="plain-metric">01:30</td>', $html);
        $this->assertMatchesRegularExpression('/<span class="plain-total-label"><span>Net<\\/span><span>Deficit<\\/span><\\/span>\\s*<strong>01:12<\\/strong>/', $html);
        $this->assertStringContainsString('<td class="plain-metric attention">00:07</td>', $html);
        $this->assertStringContainsString('<td class="plain-metric attention">01:05</td>', $html);
        $this->assertStringContainsString('class="endorsement"', $html);
        $this->assertStringContainsString('Administrative Officer II', $html);
        $this->assertStringContainsString('Recorded Sep 2, 2026 09:00', $html);
        $this->assertStringContainsString('khronoz', $html);

        $plainHeadStart = strpos($html, '<thead>');
        $plainHeadEnd = strpos($html, '</thead>');
        $this->assertNotFalse($plainHeadStart);
        $this->assertNotFalse($plainHeadEnd);
        $plainHeadingText = preg_replace('/<[^>]+>/', ' ', substr($html, $plainHeadStart, $plainHeadEnd - $plainHeadStart));
        $this->assertIsString($plainHeadingText);
        $this->assertStringNotContainsString('/', $plainHeadingText);

        $plainColgroupStart = strpos($html, '<colgroup>');
        $plainColgroupEnd = strpos($html, '</colgroup>');
        $this->assertNotFalse($plainColgroupStart);
        $this->assertNotFalse($plainColgroupEnd);
        $plainColgroup = substr($html, $plainColgroupStart, $plainColgroupEnd - $plainColgroupStart);
        $this->assertSame(1, preg_match_all('/class="plain-date-col"/', $plainColgroup));
        $this->assertSame(1, preg_match_all('/class="plain-status-col"/', $plainColgroup));
        $this->assertSame(1, preg_match_all('/class="plain-punches-col"/', $plainColgroup));
        $this->assertSame(3, preg_match_all('/class="plain-deficit-col"/', $plainColgroup));
        $this->assertSame(1, preg_match_all('/class="plain-hours-col"/', $plainColgroup));
        $this->assertSame(1, preg_match_all('/class="plain-overtime-col"/', $plainColgroup));
        $this->assertSame(1, preg_match_all('/class="plain-notes-col"/', $plainColgroup));
        $this->assertStringContainsString('Page 1 of 1', $html);

        $css = file_get_contents(resource_path('css/ledger-pdf.css'));
        $this->assertNotFalse($css);
        $this->assertStringContainsString('.plain .verification-brand { color: #6d28d9; }', $css);
        $this->assertStringContainsString('.form48 .verification-brand { color: #111827; }', $css);
        $this->assertStringContainsString('.plain-table col.plain-date-col { width: 11mm; }', $css);
        $this->assertStringContainsString('.plain-table col.plain-status-col { width: 23mm; }', $css);
        $this->assertStringContainsString('.plain-table col.plain-punches-col { width: 39mm; }', $css);
        $this->assertStringContainsString('.plain-table col.plain-deficit-col { width: 14mm; }', $css);
        $this->assertStringContainsString('.plain-table col.plain-hours-col { width: 14mm; }', $css);
        $this->assertStringContainsString('.plain-table col.plain-overtime-col { width: 14mm; }', $css);
        $this->assertStringContainsString('.plain-table col.plain-notes-col { width: 43mm; }', $css);
        $this->assertStringContainsString('.plain .totals-panel.plain-totals { grid-template-columns: repeat(7, 1fr); }', $css);
    }

    public function test_plain_footer_has_branded_preview_and_verified_official_metadata(): void
    {
        $snapshot = [
            'ledger' => ['starts' => '2026-09-01', 'ends' => '2026-09-01', 'id' => 'PLAIN-01', 'revision' => 1],
            'document' => ['id' => 'PLAIN-DOC', 'generated_at' => '2026-09-01T08:00:00Z'],
            'rendition' => ['id' => 'REND-1', 'completed_at' => '2026-09-01T07:50:00Z'],
            'workdays' => [['date' => '2026-09-01']],
        ];
        config(['app.timezone' => 'Asia/Manila']);
        $verificationUrl = 'https://example.test/verify/plain';

        $official = view('pdf.ledgers.plain', [
            'snapshot' => $snapshot,
            'preview' => false,
            'verificationUrl' => $verificationUrl,
            'qrSvg' => (new SvgWriter)->write(new QrCode(data: $verificationUrl))->getString(),
        ])->render();

        $this->assertStringContainsString('Scan to verify this record', $official);
        $this->assertStringContainsString('Document PLAIN-DOC | Rendition REND-1', $official);
        $this->assertStringContainsString('Ledger PLAIN-01 | revision 1', $official);
        $this->assertStringContainsString('Completed Sep 1, 2026 15:50', $official);
        $this->assertStringContainsString('Generated Sep 1, 2026 16:00', $official);
        $this->assertStringContainsString('Page 1 of 1', $official);
        $this->assertStringContainsString('khronoz', $official);
        $this->assertStringContainsString('alt="Verify this ledger"', $official);

        $preview = view('pdf.ledgers.plain', [
            'snapshot' => $snapshot,
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        $this->assertStringNotContainsString('PREVIEW', $preview);
        $this->assertStringNotContainsString('NOT ATTESTED', $preview);
        $this->assertStringNotContainsString('alt="Verify this ledger"', $preview);
    }

    public function test_plain_final_page_reserves_room_for_four_endorsements(): void
    {
        $workdays = [];
        for ($day = 1; $day <= 30; $day++) {
            $workdays[] = ['date' => CarbonImmutable::create(2026, 4, $day)->format('Y-m-d')];
        }

        $attestations = [
            ['role' => 'employee', 'name' => 'Employee One'],
            ['role' => 'supervisor', 'name' => 'Supervisor Two'],
            ['role' => 'timekeeper', 'name' => 'Timekeeper Three'],
            ['role' => 'head', 'name' => 'Head Four'],
        ];
        $html = view('pdf.ledgers.plain', [
            'snapshot' => [
                'ledger' => ['starts' => '2026-04-01', 'ends' => '2026-04-30'],
                'workdays' => $workdays,
                'attestations' => $attestations,
            ],
            'preview' => true,
            'verificationUrl' => null,
            'qrSvg' => null,
        ])->render();

        $this->assertSame(3, substr_count($html, 'class="page plain"'));
        $this->assertSame(1, substr_count($html, 'class="endorsements"'));
        $this->assertSame(4, substr_count($html, 'class="endorsement"'));
        $this->assertMatchesRegularExpression('/<section class="page plain">.*<strong>30<\\/strong>.*class="endorsements"/s', $html);
        $this->assertStringContainsString('Page 3 of 3', $html);
    }

    public function test_recorded_signatories_render_as_formal_vertical_endorsement_lines(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $attestations = [
            ['role' => 'employee', 'name' => 'Ana Example', 'position' => 'Administrative Officer II', 'at' => '2026-09-02T01:00:00Z'],
            ['role' => 'supervisor', 'name' => 'Rafael Villanueva', 'position' => 'Division Chief', 'at' => '2026-09-02T02:00:00Z'],
            ['role' => 'timekeeper', 'name' => 'Elena Ramos', 'position' => 'Administrative Aide VI', 'at' => '2026-09-02T03:00:00Z'],
            ['role' => 'head', 'name' => 'Alejandro Mendoza', 'position' => 'City Mayor', 'at' => '2026-09-02T04:00:00Z'],
        ];

        $html = view('pdf.ledgers.attestations', compact('attestations'))->render();

        $this->assertSame(1, substr_count($html, 'class="endorsements"'));
        $this->assertSame(4, substr_count($html, 'class="endorsement"'));
        $this->assertSame(4, substr_count($html, 'class="endorsement-name"'));
        $this->assertSame(4, substr_count($html, 'class="endorsement-position"'));
        $this->assertStringContainsString('Recorded Sep 2, 2026 09:00', $html);
        $this->assertStringContainsString('Administrative Officer II', $html);
        $this->assertStringContainsString('City Mayor', $html);
        $this->assertLessThan(strpos($html, 'Rafael Villanueva'), strpos($html, 'Ana Example'));
        $this->assertLessThan(strpos($html, 'Elena Ramos'), strpos($html, 'Rafael Villanueva'));
        $this->assertLessThan(strpos($html, 'Alejandro Mendoza'), strpos($html, 'Elena Ramos'));
        $this->assertLessThan(strpos($html, 'class="endorsement-position"'), strpos($html, 'class="endorsement-name"'));
        $this->assertStringNotContainsString('attestation', strtolower($html));
        $this->assertStringNotContainsString('signature', strtolower($html));
        $this->assertStringNotContainsString('recorded</small>', strtolower($html));
        $this->assertStringNotContainsString('class="attestation-step"', $html);
        $this->assertStringNotContainsString('class="attestation-sequence"', $html);

        $css = file_get_contents(resource_path('css/ledger-pdf.css'));
        $this->assertNotFalse($css);
        $this->assertStringContainsString('.endorsement-name { border-bottom: 1px solid var(--line-dark);', $css);
        $this->assertStringContainsString('flex-direction: column; justify-content: space-between;', $css);
        $this->assertStringContainsString('.form48 .endorsements { width: 68%; min-height: 2.3in;', $css);
        $this->assertStringContainsString('.form48 .endorsement { min-height: 0.52in;', $css);

        $this->assertSame('', trim(view('pdf.ledgers.attestations', ['attestations' => []])->render()));
    }
}

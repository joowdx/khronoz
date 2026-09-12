@php
    $allWorkdays = collect($snapshot['workdays'] ?? [])->keyBy('date');
    $rows = [];

    if (isset($snapshot['ledger']['starts'], $snapshot['ledger']['ends'])) {
        $starts = \Carbon\CarbonImmutable::parse($snapshot['ledger']['starts']);
        $ends = \Carbon\CarbonImmutable::parse($snapshot['ledger']['ends']);

        for ($date = $starts; $date->lessThanOrEqualTo($ends); $date = $date->addDay()) {
            $rows[] = $allWorkdays->get($date->toDateString(), ['date' => $date->toDateString()]);
        }
    }

    $pages = array_chunk($rows, 16);
    if ($pages === []) {
        $pages = [[]];
    }

    $duration = static fn (int $minutes): string => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    $duty = static function ($days, bool $weekend): string {
        $patterns = collect($days)->filter(fn (array $day): bool => \Carbon\CarbonImmutable::parse($day['date'])->isWeekend() === $weekend)
            ->map(function (array $day): string {
                $expected = collect($day['punches'] ?? [])->filter(fn (array $punch): bool => ! empty($punch['expected_at']))
                    ->sortBy(fn (array $punch): string => sprintf('%04d-%s', $punch['slot'] ?? 0, $punch['kind']['value'] ?? ''))
                    ->map(fn (array $punch): string => \Carbon\CarbonImmutable::parse($punch['expected_at'])->setTimezone(config('app.timezone'))->format('H:i'))
                    ->values();

                return $expected->isEmpty()
                    ? ($day['shift_name'] ?? 'Off duty')
                    : trim(($day['shift_name'] ?? '').' '.$expected->chunk(2)->map(fn ($pair): string => $pair->join('-'))->join(', '));
            })->filter()->unique()->values();

        return $patterns->isEmpty() ? 'No scheduled duty in covered range' : $patterns->join('; ');
    };

    $periodDeficit = (int) ($snapshot['totals']['tardy'] ?? 0) + (int) ($snapshot['totals']['undertime'] ?? 0);
@endphp
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:"><title>Attendance Record</title><style>{!! file_get_contents(resource_path('css/ledger-pdf.css')) !!}</style></head>
<body>
@foreach ($pages as $pageWorkdays)
<section class="page plain">
    <header class="plain-header">
        <div>
            <div class="document-kicker">Attendance and timekeeping</div>
            <h1>ATTENDANCE RECORD</h1>
            <p class="agency">{{ $snapshot['agency']['name'] ?? '' }}</p>
        </div>
        <div class="range-card">
            <span>Covered period</span>
            <strong>{{ isset($starts) ? $starts->format('M j, Y') : '-' }}</strong>
            <em>to</em>
            <strong>{{ isset($ends) ? $ends->format('M j, Y') : '-' }}</strong>
        </div>
    </header>

    @if ($preview)
        <div class="preview">PREVIEW - NOT ATTESTED</div>
    @endif

    <section class="plain-profile">
        <div class="profile-name">
            <span class="field-label">Employee</span>
            <strong>{{ $snapshot['employee']['name'] ?? '' }}</strong>
            <span>{{ $snapshot['employee']['position'] ?? 'Position not specified' }}</span>
        </div>
        <div>
            <span class="field-label">Employee no.</span>
            <strong>{{ $snapshot['employee']['number'] ?? '-' }}</strong>
        </div>
        <div>
            <span class="field-label">Office unit</span>
            <strong>{{ $snapshot['workgroup']['name'] ?? '-' }}</strong>
        </div>
        <div>
            <span class="field-label">Work scope</span>
            <strong>{{ $snapshot['ledger']['scope_label'] ?? ucfirst($snapshot['ledger']['scope'] ?? 'All') }}</strong>
        </div>
    </section>

    <section class="duty-panel plain-duty">
        <div><span class="field-label">Weekday duty</span><strong>{{ $duty($allWorkdays->values(), false) }}</strong></div>
        <div><span class="field-label">Weekend duty</span><strong>{{ $duty($allWorkdays->values(), true) }}</strong></div>
        <span class="time-note">All durations HH:MM</span>
    </section>

    @if (! empty($snapshot['filters']))
        <div class="filter-note"><strong>Detail filter:</strong> {{ collect($snapshot['filters'])->pluck('label')->join(', ') }}. Totals remain for the complete selected range.</div>
    @endif

    <table class="attendance plain-table">
        <colgroup>
            <col class="plain-date-col">
            <col class="plain-status-col">
            <col class="plain-punches-col">
            <col class="plain-deficit-col">
            <col class="plain-deficit-col">
            <col class="plain-deficit-col">
            <col class="plain-hours-col">
            <col class="plain-notes-col">
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2" class="plain-date-col">DATE</th>
                <th rowspan="2" class="plain-status-col">
                    <span>STATUS</span>
                    <span>DUTY</span>
                </th>
                <th rowspan="2" class="plain-punches-col">
                    <span>ACTUAL</span>
                    <span>PUNCHES</span>
                </th>
                <th colspan="3">DEFICIT</th>
                <th rowspan="2" class="plain-hours-col">
                    <span>HOURS</span>
                    <span>WORKED</span>
                </th>
                <th rowspan="2" class="plain-notes-col">
                    <span>REMARKS</span>
                    <span>ADJUSTMENTS</span>
                </th>
            </tr>
            <tr>
                <th class="metric-col">TARDINESS</th>
                <th class="metric-col">UNDERTIME</th>
                <th class="metric-col">TOTAL</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($pageWorkdays as $workday)
            @php
                $date = \Carbon\CarbonImmutable::parse($workday['date']);
                $notes = collect();
                $dayTardy = (int) ($workday['tardy'] ?? 0);
                $dayUndertime = (int) ($workday['undertime'] ?? 0);
                $dayDeficit = $dayTardy + $dayUndertime;

                if (! empty($workday['premium'])) {
                    $notes->push($workday['premium']['label'] ?? $workday['premium']['value']);
                }
                if (($workday['credited'] ?? 0) !== ($workday['worked'] ?? 0)) {
                    $notes->push('Credited '.$duration((int) ($workday['credited'] ?? 0)));
                }
                if (($workday['excess'] ?? 0) > 0) {
                    $notes->push('Excess '.$duration((int) $workday['excess']));
                }
                if (($workday['night'] ?? 0) > 0) {
                    $notes->push('Night '.$duration((int) $workday['night']));
                }
                if (($workday['night_excess'] ?? 0) > 0) {
                    $notes->push('Night excess '.$duration((int) $workday['night_excess']));
                }
                if (! empty($workday['exemption'])) {
                    $notes->push(($workday['exemption']['type']['label'] ?? $workday['exemption']['type']['value'] ?? 'Exempt').(empty($workday['exemption']['reference']) ? '' : ' '.$workday['exemption']['reference']));
                }
            @endphp
            <tr>
                <td class="plain-date-cell">
                    <strong>{{ $date->format('d') }}</strong>
                    <span>{{ $date->format('D') }}</span>
                </td>
                <td class="plain-status-cell">
                    <strong>{{ $workday['status']['label'] ?? 'No workday' }}</strong>
                    <span>{{ $workday['shift_name'] ?? 'No assigned shift' }}</span>
                </td>
                <td class="plain-punch-cell">
                    @forelse (collect($workday['punches'] ?? [])->sortBy(fn (array $punch): string => sprintf('%04d-%s', $punch['slot'] ?? 0, $punch['kind']['value'] ?? '')) as $punch)
                        <span class="punch-token">
                            <small>{{ strtoupper($punch['kind']['value'] ?? '') }}{{ ($punch['slot'] ?? null) === null ? '' : ' S'.$punch['slot'] }}</small>
                            @if (($punch['actual_at'] ?? null) !== null)
                                @include('pdf.ledgers.time', ['timestamp' => $punch['actual_at'], 'workDate' => $workday['date']])
                            @elseif (($punch['expected_at'] ?? null) !== null && \Carbon\CarbonImmutable::parse($punch['expected_at'])->isFuture())
                                Pending
                            @else
                                Missing
                            @endif
                        </span>
                    @empty
                        <span class="muted">No punches</span>
                    @endforelse
                </td>
                <td @class(['plain-metric', 'attention' => $dayTardy > 0])>{{ $duration($dayTardy) }}</td>
                <td @class(['plain-metric', 'attention' => $dayUndertime > 0])>{{ $duration($dayUndertime) }}</td>
                <td class="plain-metric">{{ $duration($dayDeficit) }}</td>
                <td class="plain-metric">{{ $duration((int) ($workday['worked'] ?? 0)) }}</td>
                <td class="plain-notes">{{ $notes->isEmpty() ? '-' : $notes->join(' / ') }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="empty-record">No attendance days fall within this range.</td></tr>
        @endforelse
        </tbody>
    </table>

    <section class="totals-panel plain-totals">
        @foreach ([
            'Worked' => $snapshot['totals']['worked'] ?? 0,
            'Credited' => $snapshot['totals']['credited'] ?? 0,
            'Tardy' => $snapshot['totals']['tardy'] ?? 0,
            'Undertime' => $snapshot['totals']['undertime'] ?? 0,
            'Deficit' => $periodDeficit,
            'Overtime' => $snapshot['totals']['overtime'] ?? 0,
            'Night' => $snapshot['totals']['night'] ?? 0,
        ] as $label => $minutes)
            <div @class(['plain-total', 'plain-total-primary' => $label === 'Deficit'])>
                <span>{{ $label }}</span>
                <strong>{{ $duration((int) $minutes) }}</strong>
            </div>
        @endforeach
    </section>

    <p class="certification"><strong>Certification.</strong> I certify that this attendance record accurately reflects the recorded hours of work for the covered period.</p>

    @if ($loop->last)
        @include('pdf.ledgers.attestations', ['attestations' => $snapshot['attestations'] ?? []])
    @endif
    @include('pdf.ledgers.verification', ['pageNumber' => $loop->iteration, 'pageCount' => count($pages)])
</section>
@endforeach
</body></html>

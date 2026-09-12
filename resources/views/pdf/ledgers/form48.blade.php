@php
    $starts = \Carbon\CarbonImmutable::parse($snapshot['ledger']['starts']);
    $ends = \Carbon\CarbonImmutable::parse($snapshot['ledger']['ends']);
    $months = [];
    for ($month = $starts->startOfMonth(); $month->lessThanOrEqualTo($ends); $month = $month->addMonth()) {
        $months[] = $month;
    }
    $workdays = collect($snapshot['workdays'] ?? [])->keyBy('date');
    $asOf = isset($snapshot['rendition']['completed_at']) ? \Carbon\CarbonImmutable::parse($snapshot['rendition']['completed_at']) : (isset($snapshot['ledger']['locked_at']) ? \Carbon\CarbonImmutable::parse($snapshot['ledger']['locked_at']) : now()->toImmutable());
    $duration = static function (int $minutes, bool $blankZero = false): string {
        return $blankZero && $minutes === 0 ? '' : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    };
    $duty = static function ($days, bool $weekend): string {
        $patterns = collect($days)->filter(fn (array $day): bool => \Carbon\CarbonImmutable::parse($day['date'])->isWeekend() === $weekend)
            ->map(function (array $day): string {
                $expected = collect($day['punches'] ?? [])->filter(fn (array $punch): bool => ! empty($punch['expected_at']))
                    ->sortBy(fn (array $punch): string => sprintf('%04d-%s', $punch['slot'] ?? 0, $punch['kind']['value'] ?? ''))
                    ->map(fn (array $punch): string => \Carbon\CarbonImmutable::parse($punch['expected_at'])->setTimezone(config('app.timezone'))->format('H:i'))->values();
                return $expected->isEmpty() ? ($day['shift_name'] ?? 'Off duty') : trim(($day['shift_name'] ?? '').' '.$expected->chunk(2)->map(fn ($pair): string => $pair->join('-'))->join(' / '));
            })->filter()->unique()->values();
        return $patterns->isEmpty() ? 'No scheduled duty in covered range' : $patterns->join('; ');
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:"><title>Daily Time Record</title><style>{!! file_get_contents(resource_path('css/ledger-pdf.css')) !!}</style></head>
<body>
@foreach ($months as $month)
<section class="page form48" aria-label="{{ $month->format('F Y') }}">
    <header class="document-header">
        <div class="form-number">Civil Service Form No. 48</div><div class="document-kicker">Official attendance record</div>
        <h1>DAILY TIME RECORD</h1><p class="agency">{{ $snapshot['agency']['name'] ?? '' }}</p><div class="period-chip">{{ $month->format('F Y') }}</div>
    </header>
    @if ($preview)<div class="preview">PREVIEW - NOT ATTESTED</div>@endif
    <section class="identity-grid">
        <div class="identity-primary"><span class="field-label">Employee</span><strong>{{ $snapshot['employee']['name'] ?? '' }}</strong><span>{{ $snapshot['employee']['position'] ?? 'Position not specified' }}</span></div>
        <div><span class="field-label">Employee no.</span><strong>{{ $snapshot['employee']['number'] ?? '-' }}</strong></div>
        <div><span class="field-label">Office / unit</span><strong>{{ $snapshot['workgroup']['name'] ?? '-' }}</strong></div>
        <div><span class="field-label">Covered range</span><strong>{{ $starts->format('M j, Y') }} - {{ $ends->format('M j, Y') }}</strong></div>
    </section>
    <section class="duty-panel">
        <div><span class="field-label">Weekday duty</span><strong>{{ $duty($workdays->values(), false) }}</strong></div>
        <div><span class="field-label">Weekend duty</span><strong>{{ $duty($workdays->values(), true) }}</strong></div>
        <span class="time-note">24-hour time / (+1d) next day</span>
    </section>
    @if (! empty($snapshot['filters']))<div class="filter-note"><strong>Detail filter:</strong> {{ collect($snapshot['filters'])->pluck('label')->join(', ') }}. Totals remain for the complete selected range.</div>@endif
    <table class="attendance form48-table">
        <thead><tr><th rowspan="2" class="day">Day</th><th colspan="2">AM</th><th colspan="2">PM</th><th rowspan="2" class="duration-column">Tardiness<br><small>HH:MM</small></th><th rowspan="2" class="duration-column">Undertime<br><small>HH:MM</small></th><th class="remarks">Remarks</th></tr><tr><th>In</th><th>Out</th><th>In</th><th>Out</th><th>Adjustment</th></tr></thead>
        <tbody>
        @for ($day = 1; $day <= $month->daysInMonth; $day++)
            @php
                $date = $month->day($day); $outside = $date->lessThan($starts) || $date->greaterThan($ends); $workday = $workdays->get($date->format('Y-m-d'), []);
                $punches = collect($workday['punches'] ?? [])->sortBy(fn (array $punch): string => sprintf('%04d-%s', $punch['slot'] ?? 0, $punch['kind']['value'] ?? ''));
                $slots = $punches->pluck('slot')->unique()->values(); $cells = collect([null, null, null, null]); $annotated = collect();
                $column = static function (array $punch): int {
                    $kind = $punch['kind']['value'] ?? ''; $stamp = $punch['actual_at'] ?? $punch['expected_at'] ?? null;
                    $hour = $stamp === null ? 0 : (int) \Carbon\CarbonImmutable::parse($stamp)->setTimezone(config('app.timezone'))->format('G');
                    return $kind === 'in' ? ($hour < 12 ? 0 : 2) : ($hour < 12 ? 1 : 3);
                };
                if ($slots->count() <= 2) {
                    $natural = $punches->mapWithKeys(fn (array $punch): array => [$column($punch) => $punch]);
                    if ($natural->count() === $punches->count()) { foreach ($natural as $index => $punch) { $cells->put($index, $punch); } }
                    else { foreach ($slots as $position => $slot) { $pair = $punches->where('slot', $slot); $cells->put($position * 2, $pair->first(fn (array $punch): bool => ($punch['kind']['value'] ?? null) === 'in')); $cells->put(($position * 2) + 1, $pair->first(fn (array $punch): bool => ($punch['kind']['value'] ?? null) === 'out')); } }
                } else {
                    $first = $punches->first(fn (array $punch): bool => ($punch['kind']['value'] ?? null) === 'in'); $last = $punches->reverse()->first(fn (array $punch): bool => ($punch['kind']['value'] ?? null) === 'out');
                    foreach (array_filter([$first, $last]) as $punch) { $cells->put($column($punch), $punch); }
                    $annotated = $punches->reject(fn (array $punch): bool => $punch === $first || $punch === $last);
                }
                $notes = collect();
                if (! empty($workday['status']['label'])) { $notes->push($workday['status']['label']); }
                if (! empty($workday['premium'])) { $notes->push($workday['premium']['label'] ?? $workday['premium']['value']); }
                if (($workday['excess'] ?? 0) > 0) { $notes->push('Excess '.$duration((int) $workday['excess'])); }
                if (($workday['night'] ?? 0) > 0) { $notes->push('Night '.$duration((int) $workday['night'])); }
                if (($workday['night_excess'] ?? 0) > 0) { $notes->push('Night excess '.$duration((int) $workday['night_excess'])); }
                if (! empty($workday['exemption'])) { $notes->push(($workday['exemption']['type']['label'] ?? $workday['exemption']['type']['value'] ?? 'Exempt').(empty($workday['exemption']['reference']) ? '' : ' '.$workday['exemption']['reference'])); }
            @endphp
            <tr @class(['outside' => $outside])><td class="day-cell">{{ $day }}</td>
                @if ($outside)<td colspan="7" class="outside-scope">Outside covered range</td>
                @else
                    @foreach ($cells as $punch)<td class="time-cell">@if ($punch !== null && ($punch['actual_at'] ?? null) !== null)@include('pdf.ledgers.time', ['timestamp' => $punch['actual_at'], 'workDate' => $date->format('Y-m-d')])@elseif ($punch !== null && ($punch['expected_at'] ?? null) !== null && \Carbon\CarbonImmutable::parse($punch['expected_at'])->greaterThan($asOf))<span class="pending">Pending</span>@elseif ($punch !== null)<span class="missing">Missing</span>@endif</td>@endforeach
                    <td class="metric-cell">{{ $duration((int) ($workday['tardy'] ?? 0), true) }}</td><td class="metric-cell">{{ $duration((int) ($workday['undertime'] ?? 0), true) }}</td>
                    <td class="annotations">{{ $notes->join(' / ') }}@foreach ($annotated as $extraPunch)<span class="extra-slot">{{ $loop->first && $notes->isNotEmpty() ? ' / ' : '' }}S{{ $extraPunch['slot'] ?? '' }} {{ strtoupper($extraPunch['kind']['value'] ?? '') }} @if (($extraPunch['actual_at'] ?? null) !== null)@include('pdf.ledgers.time', ['timestamp' => $extraPunch['actual_at'], 'workDate' => $date->format('Y-m-d')])@elseif (($extraPunch['expected_at'] ?? null) !== null && \Carbon\CarbonImmutable::parse($extraPunch['expected_at'])->greaterThan($asOf))pending @else missing @endif</span>@endforeach</td>
                @endif
            </tr>
        @endfor
        </tbody>
    </table>
    <section class="totals-panel">@foreach (['Worked' => $snapshot['totals']['worked'] ?? 0, 'Credited' => $snapshot['totals']['credited'] ?? 0, 'Tardy' => $snapshot['totals']['tardy'] ?? 0, 'Undertime' => $snapshot['totals']['undertime'] ?? 0, 'Overtime' => $snapshot['totals']['overtime'] ?? 0, 'Night' => $snapshot['totals']['night'] ?? 0] as $label => $minutes)<div><span>{{ $label }}</span><strong>{{ $duration((int) $minutes) }}</strong></div>@endforeach</section>
    <p class="certification"><strong>Certification.</strong> I certify on my honor that this is a true and correct report of the hours of work performed, recorded daily at arrival and departure.</p>
    @include('pdf.ledgers.attestations', ['attestations' => $snapshot['attestations'] ?? []])
    @include('pdf.ledgers.verification', ['pageNumber' => $loop->iteration, 'pageCount' => count($months)])
</section>
@endforeach
</body></html>

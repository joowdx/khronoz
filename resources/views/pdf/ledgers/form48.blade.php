@php
    $starts = \Carbon\CarbonImmutable::parse($snapshot['ledger']['starts']);
    $ends = \Carbon\CarbonImmutable::parse($snapshot['ledger']['ends']);
    $months = [];
    for ($month = $starts->startOfMonth(); $month->lessThanOrEqualTo($ends); $month = $month->addMonth()) {
        $months[] = $month;
    }
    $ledgerWorkdays = collect($snapshot['workdays'] ?? []);
    $workdays = collect($snapshot['boundaries'] ?? [])->concat($ledgerWorkdays)->keyBy('date');
    $asOf = isset($snapshot['rendition']['completed_at']) ? \Carbon\CarbonImmutable::parse($snapshot['rendition']['completed_at']) : (isset($snapshot['ledger']['locked_at']) ? \Carbon\CarbonImmutable::parse($snapshot['ledger']['locked_at']) : now()->toImmutable());
    $duration = static function (int $minutes, bool $blankZero = false): string {
        return $blankZero && $minutes === 0 ? '' : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    };
    $holidayType = static function (array $holiday): string {
        $label = $holiday['type']['label'] ?? $holiday['type']['value'] ?? $holiday['type'] ?? 'Holiday';

        return str_contains(strtolower($label), 'holiday') ? $label : $label.' holiday';
    };
    $duty = static function ($days, bool $weekend): string {
        $coveredDays = collect($days)->filter(fn (array $day): bool => \Carbon\CarbonImmutable::parse($day['date'])->isWeekend() === $weekend);
        $patterns = $coveredDays
            ->map(function (array $day): ?string {
                $expected = collect($day['punches'] ?? [])->filter(fn (array $punch): bool => ! empty($punch['expected_at']))
                    ->sortBy(fn (array $punch): string => sprintf('%04d-%s', $punch['slot'] ?? 0, $punch['kind']['value'] ?? ''))
                    ->map(fn (array $punch): string => \Carbon\CarbonImmutable::parse($punch['expected_at'])->setTimezone(config('app.timezone'))->format('H:i'))->values();

                return $expected->isEmpty() ? null : trim(($day['shift_name'] ?? '').' '.$expected->chunk(2)->map(fn ($pair): string => $pair->join('-'))->join(' / '));
            })->filter()->unique()->values();

        if ($patterns->isNotEmpty()) {
            return $patterns->join('; ');
        }

        $names = $coveredDays->pluck('shift_name')->filter()->unique()->values();

        return $names->isEmpty() ? 'No scheduled duty in covered range' : $names->join('; ');
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:"><title>Daily Time Record</title><style>{!! file_get_contents(resource_path('css/ledger-pdf.css')) !!}</style></head>
<body>
@foreach ($months as $month)
@php
    $monthStart = $month->startOfMonth();
    $monthEnd = $month->endOfMonth();
    $rows = collect([$monthStart->subDays(2), $monthStart->subDay()])
        ->map(fn ($date): array => ['date' => $date, 'boundary' => true])
        ->concat(collect(range(1, 31))->map(fn (int $day): array => [
            'date' => $day <= $month->daysInMonth ? $month->day($day) : null,
            'boundary' => false,
        ]))
        ->concat(collect([$monthEnd->addDay(), $monthEnd->addDays(2)])
            ->map(fn ($date): array => ['date' => $date, 'boundary' => true]));
    $punchEntries = $workdays->flatMap(function (array $workday): \Illuminate\Support\Collection {
        $workDate = \Carbon\CarbonImmutable::parse($workday['date'], config('app.timezone'));

        return collect($workday['punches'] ?? [])->map(function (array $punch) use ($workDate): array {
            $actual = empty($punch['actual_at']) ? null : \Carbon\CarbonImmutable::parse($punch['actual_at'])->setTimezone(config('app.timezone'));
            $expected = empty($punch['expected_at']) ? null : \Carbon\CarbonImmutable::parse($punch['expected_at'])->setTimezone(config('app.timezone'));
            $instant = $actual ?? $expected;
            $intended = $expected ?? $actual;
            $displayDate = $instant?->startOfDay() ?? $workDate;
            $isMidnightOut = ($punch['kind']['value'] ?? null) === 'out'
                && $intended?->format('H:i:s') === '00:00:00'
                && $intended->startOfDay()->greaterThan($workDate);

            if ($isMidnightOut) {
                $displayDate = $intended->subDay()->startOfDay();
            }

            return [...$punch,
                '_work_date' => $workDate->toDateString(),
                '_display_date' => $displayDate->toDateString(),
                '_midnight_out' => $isMidnightOut,
            ];
        });
    });
    $pageWorkdays = $workdays->filter(function (array $workday) use ($monthStart, $monthEnd, $punchEntries): bool {
        $workDate = $workday['date'];

        if ($workDate >= $monthStart->toDateString() && $workDate <= $monthEnd->toDateString()) {
            return true;
        }

        $dates = $punchEntries->where('_work_date', $workDate)->pluck('_display_date');

        return $dates->isNotEmpty()
            && $dates->min() <= $monthEnd->toDateString()
            && $dates->max() >= $monthStart->toDateString();
    });
    $pagePunches = $punchEntries
        ->whereIn('_work_date', $pageWorkdays->keys())
        ->sortBy(fn (array $punch): string => $punch['actual_at'] ?? $punch['expected_at'] ?? '')
        ->groupBy('_display_date');
    $continuations = $pageWorkdays->flatMap(function (array $workday): \Illuminate\Support\Collection {
        return collect($workday['punches'] ?? [])->groupBy('slot')->flatMap(function ($punches) use ($workday): \Illuminate\Support\Collection {
            $arrival = $punches->first(fn (array $punch): bool => ($punch['kind']['value'] ?? null) === 'in');
            $departure = $punches->first(fn (array $punch): bool => ($punch['kind']['value'] ?? null) === 'out');
            $startsAt = empty($arrival['actual_at'] ?? $arrival['expected_at'] ?? null) ? null : \Carbon\CarbonImmutable::parse($arrival['actual_at'] ?? $arrival['expected_at'])->setTimezone(config('app.timezone'));
            $endsAt = empty($departure['actual_at'] ?? $departure['expected_at'] ?? null) ? null : \Carbon\CarbonImmutable::parse($departure['actual_at'] ?? $departure['expected_at'])->setTimezone(config('app.timezone'));

            if ($startsAt === null || $endsAt === null || ! $endsAt->greaterThan($startsAt)) {
                return collect();
            }

            $dates = collect();
            for ($date = $startsAt->startOfDay()->addDay(); $date->lessThan($endsAt->startOfDay()); $date = $date->addDay()) {
                $dates->push(['date' => $date->toDateString(), 'work_date' => $workday['date']]);
            }

            return $dates;
        });
    })->groupBy('date');
@endphp
<section class="page form48" aria-label="{{ $month->format('F Y') }}">
    <header class="form48-header">
        <div class="form48-number">Civil Service Form No. 48</div>
        <h1>DAILY TIME RECORD</h1>
        <div class="form48-ornament" aria-hidden="true">- o0o -</div>
        <p class="form48-agency">{{ $snapshot['agency']['name'] ?? '' }}</p>

        <div class="form48-employee-name">{{ $snapshot['employee']['name'] ?? '' }}</div>
        <div class="form48-employee-caption">Employee</div>

        <div @class(['form48-identity-line', 'without-number' => empty($snapshot['employee']['number'])])>
            @if (! empty($snapshot['employee']['number']))
                <div>
                    <span>Employee no.</span>
                    <strong>{{ $snapshot['employee']['number'] }}</strong>
                </div>
            @endif
            <div>
                <span>Position</span>
                <strong>{{ $snapshot['employee']['position'] ?? 'Not specified' }}</strong>
            </div>
            <div>
                <span>Office / unit</span>
                <strong>{{ $snapshot['workgroup']['name'] ?? '-' }}</strong>
            </div>
        </div>

        <div class="form48-period-line">
            <span>For the period</span>
            <strong>{{ $starts->format('M j, Y') }} - {{ $ends->format('M j, Y') }}</strong>
            <em>Page for {{ $month->format('F Y') }}</em>
        </div>

        <div class="form48-duty-lines">
            <div class="form48-duty-label">Official hours for<br>arrival and departure</div>
            <div class="form48-duty-values">
                <div><span>Weekdays</span><strong>{{ $duty($ledgerWorkdays, false) }}</strong></div>
                <div><span>Weekends</span><strong>{{ $duty($ledgerWorkdays, true) }}</strong></div>
            </div>
        </div>
    </header>
    @if (! empty($snapshot['filters']))<div class="filter-note"><strong>Detail filter:</strong> {{ collect($snapshot['filters'])->pluck('label')->join(', ') }}. Totals remain for the complete selected range.</div>@endif
    <table class="attendance form48-table">
        <colgroup>
            <col class="day-col">
            <col class="numeric-col">
            <col class="numeric-col">
            <col class="numeric-col">
            <col class="numeric-col">
            <col class="numeric-col">
            <col class="numeric-col">
            <col class="numeric-col">
            <col class="numeric-col">
            <col class="numeric-col">
            <col class="remarks-col">
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2" class="day">DAY</th>
                <th colspan="2">AM</th>
                <th colspan="2">PM</th>
                <th colspan="3">DEFICIT</th>
                <th rowspan="2" class="hours-col"><span>HOURS</span><span>WORKED</span></th>
                <th rowspan="2" class="hours-col"><span>OVERTIME</span><span>HOURS</span></th>
                <th rowspan="2" class="remarks-col"><span>REMARKS</span><span>ADJUSTMENTS</span></th>
            </tr>
            <tr>
                <th>IN</th>
                <th>OUT</th>
                <th>IN</th>
                <th>OUT</th>
                <th class="metric-col">TARDINESS</th>
                <th class="metric-col">UNDERTIME</th>
                <th class="metric-col">TOTAL</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            @php
                $date = $row['date'];
                $dateExists = $date !== null;
                $dateKey = $date?->toDateString();
                $isBoundary = $row['boundary'];
                $outside = ! $dateExists || $isBoundary || $date->lessThan($starts) || $date->greaterThan($ends);
                $workday = $dateExists ? $workdays->get($dateKey, []) : [];
                $punches = $dateExists ? collect($pagePunches->get($dateKey, [])) : collect();
                $rowContinuations = $dateExists ? collect($continuations->get($dateKey, [])) : collect();
                $hasBoundaryContext = $isBoundary && ($punches->isNotEmpty() || $rowContinuations->isNotEmpty());
                $isContinuousOutside = ! $dateExists || ($isBoundary && ! $hasBoundaryContext);
                $holidays = collect($workday['holidays'] ?? []);
                $isHoliday = ! $isBoundary && ! $outside && $holidays->isNotEmpty();
                $isWeekend = ! $isBoundary && ! $outside && $date->isWeekend();
                $calendarLabel = collect([$isWeekend ? $date->format('l') : null])
                    ->merge($holidays->pluck('name'))
                    ->when($rowContinuations->isNotEmpty(), fn ($labels) => $labels->push('Continuation from '.\Carbon\CarbonImmutable::parse($rowContinuations->first()['work_date'])->format('M j')))
                    ->filter()
                    ->unique()
                    ->join(' • ');
                $mergeCalendarPunches = ($calendarLabel !== '' || $rowContinuations->isNotEmpty())
                    && $punches->isEmpty()
                    && collect(['worked', 'credited', 'tardy', 'undertime', 'excess', 'night', 'night_excess'])
                        ->every(fn (string $metric): bool => (int) ($workday[$metric] ?? 0) === 0);
                $cells = collect([null, null, null, null]); $annotated = collect();
                $column = static function (array $punch): int {
                    $kind = $punch['kind']['value'] ?? ''; $stamp = $punch['actual_at'] ?? $punch['expected_at'] ?? null;
                    $hour = $stamp === null ? 0 : (int) \Carbon\CarbonImmutable::parse($stamp)->setTimezone(config('app.timezone'))->format('G');

                    if ($kind === 'out' && ($punch['_midnight_out'] ?? false)) {
                        return 3;
                    }

                    return $kind === 'in' ? ($hour < 12 ? 0 : 2) : ($hour < 12 ? 1 : 3);
                };
                $dayTardiness = (int) ($workday['tardy'] ?? 0);
                $dayUndertime = (int) ($workday['undertime'] ?? 0);
                $dayDeficit = $dayTardiness + $dayUndertime;
                $dayOvertime = $dateExists && ! $isBoundary ? (int) ($snapshot['totals']['overtimeByDate'][$dateKey] ?? $workday['overtime'] ?? 0) : 0;
                foreach ($punches as $punch) {
                    $index = $column($punch);
                    if ($cells->get($index) === null) { $cells->put($index, $punch); }
                    else { $annotated->push($punch); }
                }
                $notes = collect();
                if ($isHoliday) {
                    foreach ($holidays as $holiday) { $notes->push($holidayType($holiday)); }
                } elseif (! $isWeekend) {
                    if (! empty($workday['status']['label']) && ($workday['status']['value'] ?? null) !== 'present') { $notes->push($workday['status']['label']); }
                    if (! empty($workday['premium'])) { $notes->push($workday['premium']['label'] ?? $workday['premium']['value']); }
                }
                if (($workday['credited'] ?? 0) > 0) { $notes->push('Credited '.$duration((int) $workday['credited'])); }
                if (($workday['excess'] ?? 0) > 0) { $notes->push('Excess '.$duration((int) $workday['excess'])); }
                if (($workday['night'] ?? 0) > 0) { $notes->push('Night '.$duration((int) $workday['night'])); }
                if (($workday['night_excess'] ?? 0) > 0) { $notes->push('Night excess '.$duration((int) $workday['night_excess'])); }
                if (! empty($workday['exemption'])) { $notes->push(($workday['exemption']['type']['label'] ?? $workday['exemption']['type']['value'] ?? 'Exempt').(empty($workday['exemption']['reference']) ? '' : ' '.$workday['exemption']['reference'])); }
                $notes = $notes->unique()->values();
            @endphp
            @if ($isContinuousOutside)
                <tr class="outside continuous-outside"><td colspan="11" class="outside-scope"></td></tr>
            @else
            <tr @class(['outside' => $outside && ! $hasBoundaryContext, 'boundary-context' => $hasBoundaryContext, 'holiday' => $isHoliday, 'weekend' => $isWeekend])><td @class(['day-cell', 'boundary-day' => $isBoundary])>{{ $isBoundary ? $date->format('M j') : $date->day }}</td>
                @if ($outside && ! $hasBoundaryContext)<td colspan="10" class="outside-scope"></td>
                @elseif ($mergeCalendarPunches)
                    <td colspan="4" @class(['calendar-label', 'context-time' => $rowContinuations->contains(fn (array $continuation): bool => $continuation['work_date'] < $monthStart->toDateString() || $continuation['work_date'] > $monthEnd->toDateString())])>{{ $calendarLabel }}</td>
                    <td class="metric-cell">{{ $duration($dayTardiness, true) }}</td><td class="metric-cell">{{ $duration($dayUndertime, true) }}</td><td class="metric-cell">{{ $duration($dayDeficit, true) }}</td>
                    <td class="metric-cell">{{ $duration((int) ($workday['worked'] ?? 0), true) }}</td>
                    <td class="metric-cell">{{ $duration($dayOvertime, true) }}</td>
                    <td class="annotations">{{ $notes->join(' / ') }}</td>
                @else
                    @foreach ($cells as $punch)<td @class(['time-cell', 'context-time' => $punch !== null && ($punch['_work_date'] < $monthStart->toDateString() || $punch['_work_date'] > $monthEnd->toDateString()), 'trailing-timeout' => $punch !== null && ($punch['kind']['value'] ?? null) === 'out' && $punch['_work_date'] >= $monthStart->toDateString() && $punch['_work_date'] <= $monthEnd->toDateString() && $punch['_display_date'] > $monthEnd->toDateString()])>@if ($punch !== null && ($punch['actual_at'] ?? null) !== null)@include('pdf.ledgers.time', ['timestamp' => $punch['actual_at'], 'workDate' => $dateKey])@elseif ($punch !== null && ($punch['expected_at'] ?? null) !== null && \Carbon\CarbonImmutable::parse($punch['expected_at'])->greaterThan($asOf))<span class="pending">Pending</span>@elseif ($punch !== null)<span class="missing">Missing</span>@endif</td>@endforeach
                    <td class="metric-cell">{{ $isBoundary ? '' : $duration($dayTardiness, true) }}</td><td class="metric-cell">{{ $isBoundary ? '' : $duration($dayUndertime, true) }}</td><td class="metric-cell">{{ $isBoundary ? '' : $duration($dayDeficit, true) }}</td>
                    <td class="metric-cell">{{ $isBoundary ? '' : $duration((int) ($workday['worked'] ?? 0), true) }}</td>
                    <td class="metric-cell">{{ $isBoundary ? '' : $duration($dayOvertime, true) }}</td>
                    <td class="annotations">{{ $isBoundary ? '' : $notes->join(' / ') }}@foreach ($annotated as $extraPunch)<span @class(['extra-slot', 'context-time' => $extraPunch['_work_date'] < $monthStart->toDateString() || $extraPunch['_work_date'] > $monthEnd->toDateString()])>{{ $loop->first && $notes->isNotEmpty() ? ' / ' : '' }}S{{ $extraPunch['slot'] ?? '' }} {{ strtoupper($extraPunch['kind']['value'] ?? '') }} @if (($extraPunch['actual_at'] ?? null) !== null)@include('pdf.ledgers.time', ['timestamp' => $extraPunch['actual_at'], 'workDate' => $dateKey])@elseif (($extraPunch['expected_at'] ?? null) !== null && \Carbon\CarbonImmutable::parse($extraPunch['expected_at'])->greaterThan($asOf))pending @else missing @endif</span>@endforeach</td>
                @endif
            </tr>
            @endif
        @endforeach
        </tbody>
    </table>
    @php $periodDeficit = (int) ($snapshot['totals']['tardy'] ?? 0) + (int) ($snapshot['totals']['undertime'] ?? 0); @endphp
    <table class="form48-summary" aria-label="Period totals">
        <thead><tr>@foreach ([['Hours', 'Worked'], ['Credited', 'Hours'], ['Total', 'Tardiness'], ['Total', 'Undertime'], ['Net', 'Deficit'], ['Approved', 'Overtime'], ['Night', 'Hours']] as $label)<th><span>{{ $label[0] }}</span><span>{{ $label[1] }}</span></th>@endforeach</tr></thead>
        <tbody><tr>@foreach ([$snapshot['totals']['worked'] ?? 0, $snapshot['totals']['credited'] ?? 0, $snapshot['totals']['tardy'] ?? 0, $snapshot['totals']['undertime'] ?? 0, $periodDeficit, $snapshot['totals']['overtime'] ?? 0, $snapshot['totals']['night'] ?? 0] as $minutes)<td>{{ $duration((int) $minutes) }}</td>@endforeach</tr></tbody>
    </table>
    <p class="form48-certification">I certify on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival and departure from office.</p>
    @include('pdf.ledgers.attestations', ['attestations' => $snapshot['attestations'] ?? []])
    @include('pdf.ledgers.verification', ['pageNumber' => $loop->iteration, 'pageCount' => count($months)])
</section>
@endforeach
</body></html>

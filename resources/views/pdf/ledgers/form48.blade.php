@php
    $starts = \Carbon\CarbonImmutable::parse($snapshot['ledger']['starts']);
    $ends = \Carbon\CarbonImmutable::parse($snapshot['ledger']['ends']);
    $months = [];
    for ($month = $starts->startOfMonth(); $month->lessThanOrEqualTo($ends); $month = $month->addMonth()) {
        $months[] = $month;
    }
    $workdays = collect($snapshot['workdays'] ?? [])->keyBy('date');
    $asOf = isset($snapshot['rendition']['completed_at'])
        ? \Carbon\CarbonImmutable::parse($snapshot['rendition']['completed_at'])
        : (isset($snapshot['ledger']['locked_at'])
            ? \Carbon\CarbonImmutable::parse($snapshot['ledger']['locked_at'])
            : now()->toImmutable());
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:">
    <title>Daily Time Record</title>
    <style>{!! file_get_contents(resource_path('css/ledger-pdf.css')) !!}</style>
</head>
<body>
@foreach ($months as $month)
    <section class="page form48" aria-label="{{ $month->format('F Y') }}">
        <div class="form-number">Civil Service Form No. 48</div>
        <h1>DAILY TIME RECORD</h1>
        <p class="agency">{{ $snapshot['agency']['name'] ?? '' }}</p>
        <p class="period">For the month of <strong>{{ $month->format('F Y') }}</strong></p>
        @if ($preview)
            <div class="preview">PREVIEW — NOT ATTESTED</div>
        @endif
        <div class="identity">
            <strong>{{ $snapshot['employee']['name'] ?? '' }}</strong><br>
            Employee no.: {{ $snapshot['employee']['number'] ?? '' }} · {{ $snapshot['employee']['position'] ?? '' }}<br>
            {{ $snapshot['workgroup']['name'] ?? '' }}<br>
            Ledger period: {{ $starts->format('M j, Y') }} - {{ $ends->format('M j, Y') }}. Times use 24-hour notation; (+1d) means the next day.
            @if (! empty($snapshot['filters']))
                <br>Detail filter: {{ collect($snapshot['filters'])->pluck('label')->join(', ') }}. Totals remain for the complete selected range.
            @endif
        </div>
        <table class="attendance">
            <thead>
            <tr><th rowspan="2" class="day">Day</th><th colspan="2">A.M.</th><th colspan="2">P.M.</th><th colspan="2">Undertime</th><th rowspan="2" class="remarks">Status / extra slots</th></tr>
            <tr><th>Arrival</th><th>Departure</th><th>Arrival</th><th>Departure</th><th>Hours</th><th>Minutes</th></tr>
            </thead>
            <tbody>
            @for ($day = 1; $day <= $month->daysInMonth; $day++)
                @php
                    $date = $month->day($day);
                    $outside = $date->lessThan($starts) || $date->greaterThan($ends);
                    $workday = $workdays->get($date->format('Y-m-d'), []);
                    $punches = collect($workday['punches'] ?? [])->sortBy(fn (array $punch): string => sprintf('%04d-%s', $punch['slot'] ?? 0, $punch['kind']['value'] ?? ''));
                    $undertime = (int) ($workday['undertime'] ?? 0);
                    $slots = $punches->pluck('slot')->unique()->values();
                    $cells = collect([null, null, null, null]);
                    $annotated = collect();
                    $column = static function (array $punch): int {
                        $kind = $punch['kind']['value'] ?? '';
                        $stamp = $punch['actual_at'] ?? $punch['expected_at'] ?? null;
                        $hour = $stamp === null ? 0 : (int) \Carbon\CarbonImmutable::parse($stamp)->setTimezone(config('app.timezone'))->format('G');

                        return $kind === 'in' ? ($hour < 12 ? 0 : 2) : ($hour < 12 ? 1 : 3);
                    };

                    if ($slots->count() <= 2) {
                        $natural = $punches->mapWithKeys(fn (array $punch): array => [$column($punch) => $punch]);
                        if ($natural->count() === $punches->count()) {
                            foreach ($natural as $index => $punch) {
                                $cells->put($index, $punch);
                            }
                        } else {
                            foreach ($slots as $position => $slot) {
                                $pair = $punches->where('slot', $slot);
                                $cells->put($position * 2, $pair->first(fn (array $punch): bool => ($punch['kind']['value'] ?? null) === 'in'));
                                $cells->put(($position * 2) + 1, $pair->first(fn (array $punch): bool => ($punch['kind']['value'] ?? null) === 'out'));
                            }
                        }
                    } else {
                        $first = $punches->first(fn (array $punch): bool => ($punch['kind']['value'] ?? null) === 'in');
                        $last = $punches->reverse()->first(fn (array $punch): bool => ($punch['kind']['value'] ?? null) === 'out');
                        foreach (array_filter([$first, $last]) as $punch) {
                            $cells->put($column($punch), $punch);
                        }
                        $annotated = $punches->reject(fn (array $punch): bool => $punch === $first || $punch === $last);
                    }
                @endphp
                <tr @class(['outside' => $outside])>
                    <td>{{ $day }}</td>
                    @if ($outside)
                        <td colspan="7">Outside ledger period</td>
                    @else
                        @foreach ($cells as $punch)
                            <td>
                                @if ($punch !== null && ($punch['actual_at'] ?? null) !== null)
                                    @include('pdf.ledgers.time', ['timestamp' => $punch['actual_at'], 'workDate' => $date->format('Y-m-d')])
                                @elseif ($punch !== null && ($punch['expected_at'] ?? null) !== null && \Carbon\CarbonImmutable::parse($punch['expected_at'])->greaterThan($asOf))
                                    <span title="Pending punch">…</span>
                                @elseif ($punch !== null)
                                    <span title="Missing punch">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td>{{ $undertime > 0 ? intdiv($undertime, 60) : '' }}</td>
                        <td>{{ $undertime > 0 ? $undertime % 60 : '' }}</td>
                        <td class="annotations">{{ $workday['status']['label'] ?? ($workday === [] ? 'No workday' : '') }}
                            @if (! empty($workday['exemption']))
                                · {{ $workday['exemption']['type']['label'] ?? $workday['exemption']['type']['value'] ?? 'Exempt' }}{{ empty($workday['exemption']['reference']) ? '' : ' '.$workday['exemption']['reference'] }}
                            @endif
                            @foreach ($annotated as $extraPunch)
                                <span class="extra-slot">; slot {{ $extraPunch['slot'] ?? '' }} {{ $extraPunch['kind']['value'] ?? '' }}
                                    @if (($extraPunch['actual_at'] ?? null) !== null)
                                        @include('pdf.ledgers.time', ['timestamp' => $extraPunch['actual_at'], 'workDate' => $date->format('Y-m-d')])
                                    @elseif (($extraPunch['expected_at'] ?? null) !== null && \Carbon\CarbonImmutable::parse($extraPunch['expected_at'])->greaterThan($asOf))
                                        pending
                                    @else
                                        missing
                                    @endif
                                </span>
                            @endforeach
                        </td>
                    @endif
                </tr>
            @endfor
            </tbody>
        </table>
        <div class="totals">Ledger totals (minutes): Worked {{ $snapshot['totals']['worked'] ?? 0 }} · Credited {{ $snapshot['totals']['credited'] ?? 0 }} · Tardy {{ $snapshot['totals']['tardy'] ?? 0 }} · Undertime {{ $snapshot['totals']['undertime'] ?? 0 }} · Excess {{ $snapshot['totals']['excess'] ?? 0 }} · Overtime {{ $snapshot['totals']['overtime'] ?? 0 }} · Night {{ $snapshot['totals']['night'] ?? 0 }} · Night excess {{ $snapshot['totals']['nightExcess'] ?? $snapshot['totals']['night_excess'] ?? 0 }}</div>
        <p class="certification">I certify on my honor that the above is a true and correct report of the hours of work performed, recorded daily at the time of arrival at and departure from the office.</p>
        @include('pdf.ledgers.attestations', ['attestations' => $snapshot['attestations'] ?? []])
        @include('pdf.ledgers.verification', ['pageNumber' => $loop->iteration, 'pageCount' => count($months)])
    </section>
@endforeach
</body>
</html>

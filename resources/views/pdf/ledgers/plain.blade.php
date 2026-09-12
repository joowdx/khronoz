@php
    $workdays = collect($snapshot['workdays'] ?? [])->keyBy('date');
    $rows = [];
    if (isset($snapshot['ledger']['starts'], $snapshot['ledger']['ends'])) {
        $starts = \Carbon\CarbonImmutable::parse($snapshot['ledger']['starts']);
        $ends = \Carbon\CarbonImmutable::parse($snapshot['ledger']['ends']);
        for ($date = $starts; $date->lessThanOrEqualTo($ends); $date = $date->addDay()) {
            $rows[] = $workdays->get($date->toDateString(), ['date' => $date->toDateString()]);
        }
    }
    $pages = array_chunk($rows, 15);
    if ($pages === []) {
        $pages = [[]];
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:">
    <title>Attendance Record</title>
    <style>{!! file_get_contents(resource_path('css/ledger-pdf.css')) !!}</style>
</head>
<body>
@foreach ($pages as $workdays)
    <section class="page plain">
        <h1>ATTENDANCE RECORD</h1>
        <p class="agency">{{ $snapshot['agency']['name'] ?? '' }}</p>
        <p class="period">{{ $snapshot['ledger']['starts'] ?? '' }} - {{ $snapshot['ledger']['ends'] ?? '' }}</p>
        @if ($preview)
            <div class="preview">PREVIEW — NOT ATTESTED</div>
        @endif
        <div class="identity"><strong>{{ $snapshot['employee']['name'] ?? '' }}</strong><br>Employee no.: {{ $snapshot['employee']['number'] ?? '' }} · {{ $snapshot['employee']['position'] ?? '' }}<br>{{ $snapshot['workgroup']['name'] ?? '' }}@if (! empty($snapshot['filters']))<br>Detail filter: {{ collect($snapshot['filters'])->pluck('label')->join(', ') }}. Totals remain for the complete selected range.@endif</div>
        <table class="attendance">
            <thead><tr><th class="date">Date</th><th class="status">Status</th><th>Worked</th><th>Credited</th><th>Tardy</th><th>Undertime</th><th>Excess</th><th>Night</th></tr></thead>
            <tbody>
            @foreach ($workdays as $workday)
                <tr><td>{{ $workday['date'] ?? '' }}</td><td>{{ $workday['status']['label'] ?? 'No workday' }}@if (! empty($workday['premium'])) · {{ $workday['premium']['label'] ?? $workday['premium']['value'] }}@endif @if (! empty($workday['exemption'])) · {{ $workday['exemption']['type']['label'] ?? $workday['exemption']['type']['value'] ?? 'Exempt' }}{{ empty($workday['exemption']['reference']) ? '' : ' '.$workday['exemption']['reference'] }}@endif</td><td>{{ $workday['worked'] ?? 0 }}</td><td>{{ $workday['credited'] ?? 0 }}</td><td>{{ $workday['tardy'] ?? 0 }}</td><td>{{ $workday['undertime'] ?? 0 }}</td><td>{{ $workday['excess'] ?? 0 }}</td><td>{{ $workday['night'] ?? 0 }}</td></tr>
                @if (! empty($workday['punches']))
                    <tr class="punch-detail"><td colspan="8">{{ $workday['shift_name'] ?? '' }}
                        @foreach (collect($workday['punches'])->sortBy('slot') as $punch)
                            Slot {{ $punch['slot'] ?? '' }} {{ $punch['kind']['label'] ?? $punch['kind']['value'] ?? '' }}:
                            @if (($punch['actual_at'] ?? null) !== null)
                                @include('pdf.ledgers.time', ['timestamp' => $punch['actual_at'], 'workDate' => $workday['date']])
                            @elseif (($punch['expected_at'] ?? null) !== null && \Carbon\CarbonImmutable::parse($punch['expected_at'])->isFuture())
                                pending
                            @else
                                missing
                            @endif
                        @endforeach
                    </td></tr>
                @endif
            @endforeach
            </tbody>
        </table>
        <div class="totals">All durations are minutes. Ledger totals: Worked {{ $snapshot['totals']['worked'] ?? 0 }} · Credited {{ $snapshot['totals']['credited'] ?? 0 }} · Tardy {{ $snapshot['totals']['tardy'] ?? 0 }} · Undertime {{ $snapshot['totals']['undertime'] ?? 0 }} · Excess {{ $snapshot['totals']['excess'] ?? 0 }} · Overtime {{ $snapshot['totals']['overtime'] ?? 0 }} · Night {{ $snapshot['totals']['night'] ?? 0 }} · Night excess {{ $snapshot['totals']['nightExcess'] ?? $snapshot['totals']['night_excess'] ?? 0 }}</div>
        @include('pdf.ledgers.attestations', ['attestations' => $snapshot['attestations'] ?? []])
        @include('pdf.ledgers.verification', ['pageNumber' => $loop->iteration, 'pageCount' => count($pages)])
    </section>
@endforeach
</body>
</html>

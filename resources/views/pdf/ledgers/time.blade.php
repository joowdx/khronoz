@if (! empty($timestamp))
    @php
        $time = \Carbon\CarbonImmutable::parse($timestamp)->setTimezone(config('app.timezone'));
        $offset = (int) \Carbon\CarbonImmutable::parse($workDate, config('app.timezone'))->startOfDay()->diffInDays($time->startOfDay(), false);
    @endphp
    {{ $time->format('H:i') }}@if ($offset !== 0)<small>({{ $offset > 0 ? '+' : '' }}{{ $offset }}d)</small>@endif
@endif

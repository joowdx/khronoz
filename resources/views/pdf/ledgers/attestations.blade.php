@if (count($attestations) > 0)
    <section class="endorsements">
        @foreach ($attestations as $attestation)
            @php
                $role = ucwords(str_replace('_', ' ', (string) ($attestation['role'] ?? '')));
                $position = $attestation['position'] ?? $attestation['designation'] ?? '';
                $recordedAt = empty($attestation['at'])
                    ? null
                    : \Carbon\CarbonImmutable::parse($attestation['at'])->setTimezone(config('app.timezone'))->format('M j, Y H:i');
            @endphp
            <div class="endorsement">
                <div class="endorsement-name">{{ $attestation['name'] ?? '' }}</div>
                <div class="endorsement-position">{{ $position }}</div>
                <div class="endorsement-meta">
                    <span class="endorsement-role">{{ $role }}</span>
                    @if ($recordedAt)<time datetime="{{ $attestation['at'] }}">Recorded {{ $recordedAt }}</time>@endif
                </div>
            </div>
        @endforeach
    </section>
@endif

<section class="attestation-panel" aria-label="Application attestations">
    <div class="section-heading">
        <span>Attestation chain</span>
        <small>{{ count($attestations) }} recorded</small>
    </div>
    <div class="attestation-chain">
        @forelse ($attestations as $attestation)
            @php
                $role = str_replace('_', ' ', (string) ($attestation['role'] ?? 'Attestor'));
                $position = $attestation['position'] ?? $attestation['designation'] ?? null;
            @endphp
            <div class="attestation-step">
                <span class="attestation-sequence">{{ $attestation['sequence'] ?? $loop->iteration }}</span>
                <div class="attestation-person">
                    <strong>{{ $attestation['name'] ?? '' }}</strong>
                    <span>{{ $position ?: ucfirst($role) }}</span>
                </div>
                <div class="attestation-record">
                    <strong>{{ ucfirst($role) }}</strong>
                    <span>{{ empty($attestation['at']) ? 'Not recorded' : \Carbon\CarbonImmutable::parse($attestation['at'])->setTimezone(config('app.timezone'))->format('M j, Y H:i') }}</span>
                </div>
            </div>
        @empty
            <div class="attestation-empty">No attestations recorded.</div>
        @endforelse
    </div>
</section>

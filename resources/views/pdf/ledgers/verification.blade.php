@php
    $generatedAt = isset($snapshot['document']['generated_at'])
        ? \Carbon\CarbonImmutable::parse($snapshot['document']['generated_at'])->setTimezone(config('app.timezone'))->format('M j, Y H:i')
        : null;
    $completedAt = isset($snapshot['rendition']['completed_at'])
        ? \Carbon\CarbonImmutable::parse($snapshot['rendition']['completed_at'])->setTimezone(config('app.timezone'))->format('M j, Y H:i')
        : null;
@endphp
<footer class="verification">
    @include('pdf.ledgers.khronoz-brand')
    @if (! $preview && $verificationUrl && $qrSvg)
        <img src="data:image/svg+xml;base64,{{ base64_encode($qrSvg) }}" alt="Verify this ledger">
        <div class="verification-copy">
            <strong>Scan to verify this record</strong>
            <span>Document {{ $snapshot['document']['id'] ?? '-' }} | Rendition {{ $snapshot['rendition']['id'] ?? '-' }}</span>
            <span>Ledger {{ $snapshot['ledger']['id'] ?? '-' }} | revision {{ $snapshot['ledger']['revision'] ?? '-' }}</span>
            @if ($completedAt)<span>Completed {{ $completedAt }}</span>@endif
        </div>
    @endif
    <div class="footer-meta">
        @if ($generatedAt)
            <span>Generated {{ $generatedAt }}</span>
        @endif
        <strong>Page {{ $pageNumber }} of {{ $pageCount }}</strong>
    </div>
</footer>

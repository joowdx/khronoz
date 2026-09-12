<footer class="verification">
    @if (! $preview && $verificationUrl && $qrSvg)
        <img src="data:image/svg+xml;base64,{{ base64_encode($qrSvg) }}" alt="Verify attested ledger">
        <div>
            <p>Verify this attested ledger:</p>
            <p>{{ $verificationUrl }}</p>
            <p>Ledger {{ $snapshot['ledger']['id'] ?? '—' }} · revision {{ $snapshot['ledger']['revision'] ?? '—' }}</p>
            <p>Rendition {{ $snapshot['rendition']['id'] ?? '—' }} · completed {{ $snapshot['rendition']['completed_at'] ?? '—' }}</p>
            <p>Generated {{ $snapshot['document']['generated_at'] ?? '—' }}</p>
            <p>Application attestations; no cryptographic digital signature.</p>
        </div>
    @else
        <div>PREVIEW — NOT ATTESTED</div>
    @endif
    <span class="page-number">{{ $pageNumber }} / {{ $pageCount }}</span>
</footer>

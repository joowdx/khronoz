<footer class="verification">
    @if (! $preview && $verificationUrl && $qrSvg)
        <img src="data:image/svg+xml;base64,{{ base64_encode($qrSvg) }}" alt="Verify attested ledger">
        <div class="verification-copy">
            <strong>Verify this attested ledger</strong>
            <span>{{ $verificationUrl }}</span>
            <span>Ledger {{ $snapshot['ledger']['id'] ?? '-' }} / revision {{ $snapshot['ledger']['revision'] ?? '-' }}</span>
            <span>Rendition {{ $snapshot['rendition']['id'] ?? '-' }} / completed {{ $snapshot['rendition']['completed_at'] ?? '-' }}</span>
            <span>Application attestations only; no cryptographic digital signature.</span>
        </div>
    @else
        <div class="preview-footer">PREVIEW - NOT ATTESTED</div>
    @endif
    <div class="footer-meta">
        @if (! $preview)
            <span>Generated {{ $snapshot['document']['generated_at'] ?? '-' }}</span>
        @endif
        <strong>Page {{ $pageNumber }} of {{ $pageCount }}</strong>
    </div>
</footer>

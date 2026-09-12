<table class="attestations">
    <thead><tr><th>Attestation</th><th>Name</th><th>Recorded at</th></tr></thead>
    <tbody>
    @forelse ($attestations as $attestation)
        <tr><td>{{ $attestation['role'] ?? '' }}</td><td>{{ $attestation['name'] ?? '' }}</td><td>{{ $attestation['at'] ?? '' }}</td></tr>
    @empty
        <tr><td colspan="3">No attestations recorded.</td></tr>
    @endforelse
    </tbody>
</table>

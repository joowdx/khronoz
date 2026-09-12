import { StatusPill } from '@/components/ui/badge';
import type { Ledger, Rendition } from '@/types';
export function RenditionStatus({ rendition }: { rendition: Rendition }) {
    if (rendition.superseded_at) return <StatusPill>Superseded</StatusPill>;
    switch (rendition.status) {
        case 'pending':
            return <StatusPill variant="attention">Preparing PDF</StatusPill>;
        case 'failed':
            return <StatusPill variant="destructive">PDF failed</StatusPill>;
        case 'ready':
            return <StatusPill variant="positive">Archived PDF ready</StatusPill>;
        case 'unstored':
            return <StatusPill variant="positive">Attested · not archived</StatusPill>;
    }
}
export function LedgerStatus({ ledger }: { ledger: Ledger }) {
    if (ledger.unlocked_at) return <StatusPill>Unlocked revision</StatusPill>;
    const current = ledger.renditions?.find((item) => !item.superseded_at);
    return current ? (
        <RenditionStatus rendition={current} />
    ) : (
        <StatusPill variant="attention">Awaiting attestation</StatusPill>
    );
}

import { Download } from 'lucide-react';
import { LedgerRecord } from '@/components/ledger-record';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { StatusPill } from '@/components/ui/badge';
import { formatDay } from '@/lib/dates';
import type { LedgerDocument, LedgerSnapshot, LedgerView } from '@/types';
export default function Verify({
    snapshot,
    superseded_at,
    document,
    download_url,
}: {
    snapshot: LedgerSnapshot;
    superseded_at: string | null;
    document: LedgerDocument | null;
    download_url: string | null;
}) {
    const totals = snapshot.totals;
    const view: LedgerView = {
        ...totals,
        workdays: snapshot.workdays,
        night_excess: totals.night_excess ?? totals.nightExcess ?? 0,
        tardy_occurrences: totals.tardy_occurrences ?? totals.tardyOccurrences ?? 0,
        undertime_occurrences: totals.undertime_occurrences ?? totals.undertimeOccurrences ?? 0,
    };
    return (
        <main className="min-h-screen overflow-x-auto px-6 py-10 sm:px-10">
            <div className="mx-auto max-w-[1440px] min-w-0">
                <header className="mb-8 grid gap-4">
                    <p className="text-muted-foreground text-sm font-semibold">khronoz · Ledger verification</p>
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-3xl font-bold">{snapshot.employee?.name ?? 'Attested ledger'}</h1>
                            <p className="text-muted-foreground mt-2">
                                {snapshot.agency?.name} · {snapshot.employee?.number}
                            </p>
                            <p className="mt-2 text-sm">
                                {formatDay(snapshot.ledger.starts)} – {formatDay(snapshot.ledger.ends)} ·{' '}
                                {snapshot.ledger.scope_label ?? snapshot.ledger.scope} · Revision {snapshot.ledger.revision}
                            </p>
                        </div>
                        <StatusPill variant={superseded_at ? 'attention' : 'positive'}>
                            {superseded_at ? 'Superseded' : 'Current attestation'}
                        </StatusPill>
                    </div>
                    {superseded_at && (
                        <Alert variant="attention">
                            This attestation was superseded on {superseded_at}. The figures and recorded acts below are
                            retained as they were certified.
                        </Alert>
                    )}
                    <p className="text-muted-foreground max-w-3xl text-sm">
                        This is the frozen ledger certified by the people listed below. Its verification is available
                        independently of PDF archiving.
                    </p>
                </header>
                <LedgerRecord starts={snapshot.ledger.starts} ends={snapshot.ledger.ends} view={view} sticky={false} />
                <section className="mt-8 grid gap-4 border-t pt-8">
                    <h2 className="text-lg font-semibold">Recorded attestations</h2>
                    <ol className="grid gap-3">
                        {snapshot.attestations.map((act) => (
                            <li key={act.id} className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                <span className="font-semibold">
                                    {act.sequence}. {act.name}
                                </span>
                                <span className="capitalize">{act.role}</span>
                                <time className="text-muted-foreground">{act.at}</time>
                            </li>
                        ))}
                    </ol>
                </section>
                {document && (
                    <section className="mt-8 grid max-w-3xl gap-4 border-t pt-8">
                        <h2 className="text-lg font-semibold">Archived PDF</h2>
                        <p className="text-sm break-all">
                            <span className="font-medium">SHA-256</span> {document.digest}
                        </p>
                        <p className="text-muted-foreground text-sm">
                            {document.bytes.toLocaleString()} bytes · {document.name}
                        </p>
                        {download_url && (
                            <Button asChild className="w-fit" variant="outline">
                                <a href={download_url}>
                                    <Download aria-hidden />
                                    Download exact PDF
                                </a>
                            </Button>
                        )}
                    </section>
                )}
            </div>
        </main>
    );
}

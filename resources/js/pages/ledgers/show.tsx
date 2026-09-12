import { Form, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import { Download, ExternalLink } from 'lucide-react';
import { FormErrors } from '@/components/form-errors';
import { LedgerLockControl } from '@/components/ledger-lock';
import { LedgerRecord } from '@/components/ledger-record';
import { PageHeader } from '@/components/page-header';
import { LedgerStatus, RenditionStatus } from '@/components/rendition-status';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDay } from '@/lib/dates';
import { download, index } from '@/routes/ledgers';
import { store as attest, destroy as withdraw } from '@/routes/ledgers/attestations';
import { download as renditionDownload, retry } from '@/routes/ledgers/renditions';
import type { Ledger, LedgerView } from '@/types';
const COLUMNS = { state: 210, generated: 200, actions: 340 };
const TABLE_WIDTH = Object.values(COLUMNS).reduce((sum, value) => sum + value, 180);
export default function Show({
    ledger,
    view,
    can,
}: {
    ledger: Ledger;
    view: LedgerView;
    can: { lock: boolean; unlock: boolean; attest: boolean; retry: boolean };
}) {
    const attestations = ledger.attestations ?? [];
    const active = attestations.filter((item) => !item.withdrawn_at);
    const renditions = ledger.renditions ?? [];
    const next = ledger.signers[active.length];
    const preparing = renditions.some((item) => item.status === 'pending' && !item.superseded_at);
    const { start, stop } = usePoll(5000, { only: ['ledger', 'can'] }, { autoStart: false });
    useEffect(() => {
        if (preparing) {
            start();
        } else {
            stop();
        }
    }, [preparing, start, stop]);
    return (
        <AppLayout>
            <PageHeader
                title={ledger.identity.employee?.name ?? ledger.employee?.name ?? 'Ledger'}
                breadcrumb={{ title: 'Ledgers', href: index.url({ query: { month: ledger.starts.slice(0, 7) } }) }}
                description={
                    formatDay(ledger.starts) +
                    ' – ' +
                    formatDay(ledger.ends) +
                    ' · ' +
                    ledger.scope.label +
                    ' · Revision ' +
                    ledger.revision
                }
                actions={
                    <Button asChild>
                        <a href={download.url(ledger)}>
                            <Download aria-hidden />
                            Download PDF
                        </a>
                    </Button>
                }
            />
            <div className="mb-8 flex flex-wrap items-center justify-between gap-4">
                <LedgerStatus ledger={ledger} />
                <LedgerLockControl ledger={ledger} allowed={can.unlock && active.length === 0} />
            </div>
            {ledger.unlocked_at && (
                <Alert className="mb-8">
                    This revision was unlocked on {ledger.unlocked_at}. Its figures remain frozen for the historical
                    record.
                </Alert>
            )}
            <LedgerRecord starts={ledger.starts} ends={ledger.ends} view={view} />
            <section className="mt-8 grid gap-5 border-t pt-8">
                <h2 className="text-lg font-semibold">Certification policy</h2>
                <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt className="text-muted-foreground">Cadence</dt>
                        <dd className="pt-1 font-medium">{ledger.cadence?.name ?? 'Calendar month'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Template</dt>
                        <dd className="pt-1 font-medium">
                            {ledger.policy.template === 'plain' ? 'Plain time record' : 'CSC Form 48'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Supervisor</dt>
                        <dd className="pt-1 font-medium">
                            {ledger.policy.supervisor === 'substantive'
                                ? 'Substantive workgroup'
                                : 'Operative workgroup'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Head kind</dt>
                        <dd className="pt-1 font-medium">{ledger.policy.head_kind ?? 'Not required'}</dd>
                    </div>
                </dl>
            </section>
            <section className="mt-8 grid gap-5 border-t pt-8">
                <h2 className="text-lg font-semibold">Attestations</h2>
                <p className="text-muted-foreground max-w-2xl text-sm">
                    Each act certifies this revision. To correct attendance, withdraw attestations from the latest back
                    to the first, then unlock.
                </p>
                <ol className="grid max-w-3xl gap-3">
                    {ledger.signers.map((signer, position) => {
                        const act = active.find((item) => item.sequence === position + 1);
                        return (
                            <li key={signer.role} className="flex flex-wrap items-center gap-4 border-b py-3">
                                <span className="text-muted-foreground w-6 text-sm">{position + 1}</span>
                                <div className="mr-auto">
                                    <p className="text-sm font-semibold capitalize">{signer.role}</p>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        {act
                                            ? act.name + ' · ' + act.at
                                            : position === active.length
                                              ? 'Next required role'
                                              : 'Waiting for the preceding role'}
                                    </p>
                                </div>
                                {act?.can_withdraw && (
                                    <Form {...withdraw.form([ledger, act])} options={{ preserveScroll: true }}>
                                        {({ errors, processing }) => (
                                            <div className="grid gap-2">
                                                <FormErrors errors={errors} />
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Withdraw latest
                                                </Button>
                                            </div>
                                        )}
                                    </Form>
                                )}
                            </li>
                        );
                    })}
                </ol>
                {can.attest && next && !ledger.unlocked_at && (
                    <Form
                        {...attest.form(ledger)}
                        className="grid max-w-lg gap-3"
                        options={{ preserveScroll: true }}
                        disableWhileProcessing
                    >
                        {({ errors, processing }) => (
                            <>
                                <input type="hidden" name="role" value={next.role} />
                                <FormErrors errors={errors} />
                                <Button type="submit" variant="outline" className="w-fit" disabled={processing}>
                                    Attest as {next.role}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
                {attestations.some((item) => item.withdrawn_at) && (
                    <div className="text-muted-foreground grid gap-2 text-sm">
                        {attestations
                            .filter((item) => item.withdrawn_at)
                            .map((item) => (
                                <p key={item.id}>
                                    {item.name} · {item.role} · withdrawn {item.withdrawn_at}
                                </p>
                            ))}
                    </div>
                )}
            </section>
            <section className="mt-8 grid gap-5 border-t pt-8">
                <h2 className="text-lg font-semibold">PDF and verification history</h2>
                {renditions.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        Verification becomes available when the required attestations are complete. You can download
                        this locked record now.
                    </p>
                ) : (
                    <Card className="min-w-min overflow-visible">
                        <Table style={{ minWidth: TABLE_WIDTH }}>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Rendition</TableHead>
                                    <TableHead style={{ width: COLUMNS.state }}>Status</TableHead>
                                    <TableHead style={{ width: COLUMNS.generated }}>Generated</TableHead>
                                    <TableHead style={{ width: COLUMNS.actions }}>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {renditions.map((rendition) => (
                                    <TableRow key={rendition.id}>
                                        <TableCell>
                                            <p className="font-medium">Rendition {rendition.revision}</p>
                                            {rendition.document && (
                                                <p
                                                    className="text-muted-foreground mt-1 max-w-56 text-xs break-all"
                                                    title={rendition.document.digest}
                                                >
                                                    SHA-256 {rendition.document.digest}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <RenditionStatus rendition={rendition} />
                                        </TableCell>
                                        <TableCell>{rendition.generated_at ?? '—'}</TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-2">
                                                <Button asChild variant="outline" size="sm">
                                                    <a href={renditionDownload.url([ledger, rendition])}>
                                                        <Download aria-hidden />
                                                        {rendition.status === 'ready' ? 'Exact PDF' : 'Download PDF'}
                                                    </a>
                                                </Button>
                                                <Button asChild variant="ghost" size="sm">
                                                    <a
                                                        href={rendition.verification_url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        <ExternalLink aria-hidden />
                                                        Verify
                                                    </a>
                                                </Button>
                                                {can.retry &&
                                                    rendition.status === 'failed' &&
                                                    !rendition.superseded_at && (
                                                        <Form {...retry.form([ledger, rendition])}>
                                                            {({ errors, processing }) => (
                                                                <div className="grid gap-2">
                                                                    <FormErrors errors={errors} />
                                                                    <Button
                                                                        type="submit"
                                                                        variant="outline"
                                                                        size="sm"
                                                                        disabled={processing}
                                                                    >
                                                                        Retry PDF
                                                                    </Button>
                                                                </div>
                                                            )}
                                                        </Form>
                                                    )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </section>
        </AppLayout>
    );
}

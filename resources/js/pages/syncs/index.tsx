import { Link, router } from '@inertiajs/react';
import { Combobox } from '@/components/combobox';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { formatDay } from '@/lib/dates';
import { index } from '@/routes/syncs';
import type { Sync, Terminal } from '@/types';

interface Filters {
    terminal: string;
    failed: boolean;
}

interface Pagination {
    from: number | null;
    to: number | null;
    total: number;
    previous: string | null;
    next: string | null;
}

const PARTIAL = ['syncs', 'pagination', 'filters'];

const COLUMNS = {
    when: 170,
    device: 100,
    accepted: 110,
    duplicates: 110,
    rejected: 110,
} as const;

/** What the flexible File column needs for a real export name plus a refusal. */
const FLEX_MIN = 280;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

function query(filters: Filters, page?: string): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.terminal !== '') {
        params.terminal = filters.terminal;
    }

    if (filters.failed) {
        params.failed = '1';
    }

    if (page) {
        params.page = page;
    }

    return params;
}

/**
 * The history of getting punches in.
 *
 * The rows worth finding are the **failed** ones, and not because something
 * broke technically: a file naming two device numbers is refused whole as
 * probable tampering (decision 44), and the row here carrying its reason is
 * the only lasting trace of the attempt. Without this screen a refusal is a
 * flash message the second attempt looks identical to.
 */
export default function Index({
    syncs,
    pagination,
    filters,
    terminals,
}: {
    syncs: Sync[];
    pagination: Pagination;
    filters: Filters;
    terminals: Terminal[];
}) {
    function go(next: Partial<Filters>) {
        router.get(index.url(), query({ ...filters, ...next }), {
            only: PARTIAL,
            preserveState: true,
            preserveScroll: true,
        });
    }

    return (
        <AppLayout>
            <PageHeader
                title="Imports"
                description="Every run that brought punches in, and every one that was refused."
            />

            <div className="mb-6 flex flex-wrap items-end gap-3">
                <Field className="w-[240px]" label="Device" htmlFor="terminal">
                    {({ id }) => (
                        <Combobox
                            id={id}
                            value={filters.terminal === '' ? null : filters.terminal}
                            onValueChange={(value) => go({ terminal: value ?? '' })}
                            placeholder="Every device"
                            searchPlaceholder="Search devices"
                            empty="No device by that name."
                            clearLabel="Every device"
                            options={terminals.map((terminal) => ({
                                value: terminal.id,
                                label: terminal.name,
                                keywords: [terminal.code],
                                trigger: terminal.name,
                            }))}
                        />
                    )}
                </Field>

                <Field label="Only show" htmlFor="only">
                    {({ id }) => (
                        <ToggleGroup
                            id={id}
                            type="multiple"
                            value={filters.failed ? ['failed'] : []}
                            onValueChange={(value) => go({ failed: value.includes('failed') })}
                            variant="outline"
                        >
                            <ToggleGroupItem value="failed">Refused</ToggleGroupItem>
                        </ToggleGroup>
                    )}
                </Field>
            </div>

            {syncs.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title={filters.failed ? 'Nothing has been refused' : 'Nothing imported yet'}
                        description={
                            filters.failed
                                ? 'No file has been turned away. A file recorded by another device, or naming several, would be refused whole and appear here.'
                                : 'Import a device’s attlog export from the Terminals screen and the run will be recorded here.'
                        }
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>Runs</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {pagination.from}–{pagination.to} of {pagination.total.toLocaleString()}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">Ingestion runs, most recent first</TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.when }}>When</TableHead>
                                <TableHead style={{ width: COLUMNS.device }}>Device</TableHead>
                                <TableHead>File</TableHead>
                                <TableHead style={{ width: COLUMNS.accepted }} numeric>
                                    New
                                </TableHead>
                                <TableHead style={{ width: COLUMNS.duplicates }} numeric>
                                    Already had
                                </TableHead>
                                <TableHead style={{ width: COLUMNS.rejected }} numeric>
                                    Unreadable
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {syncs.map((sync) => (
                                <TableRow key={sync.id}>
                                    <TableCell className="tabular-nums">
                                        <span className="flex flex-col">
                                            <span>{formatDay(sync.started_at.slice(0, 10))}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {sync.started_at.slice(11, 16)}
                                            </span>
                                        </span>
                                    </TableCell>
                                    <TableCell className="tabular-nums">{sync.terminal?.code ?? '—'}</TableCell>
                                    {/*
                                      A refused run's whole value is its reason,
                                      so the error sits under the filename
                                      rather than behind a status chip. The
                                      counters beside it are all zero, which is
                                      the other half of the statement: nothing
                                      was written.
                                    */}
                                    <TableCell className="max-w-0">
                                        <span className="flex min-w-0 flex-col">
                                            <span className="truncate">
                                                {/*
                                                  A run with no filename is
                                                  named by how it started, and
                                                  the words come from
                                                  SyncTrigger. `capitalize` on
                                                  the raw value used to print
                                                  "Import" and "Push" where
                                                  the enum says "File import"
                                                  and "Pushed by device" —
                                                  wrong on exactly the two
                                                  runs that have no filename
                                                  to fall back from.
                                                */}
                                                {sync.reference ?? (
                                                    <span className="text-muted-foreground">{sync.trigger.label}</span>
                                                )}
                                            </span>
                                            {sync.status === 'failed' && (
                                                <span className="text-destructive text-xs">Refused — {sync.error}</span>
                                            )}
                                            {sync.status === 'running' && (
                                                <span className="text-muted-foreground text-xs">Still running</span>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {sync.accepted.toLocaleString()}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-right tabular-nums">
                                        {sync.duplicates.toLocaleString()}
                                    </TableCell>
                                    {/*
                                      Zero is the expected answer, so it stays
                                      quiet; anything else is a line the parser
                                      could not read and is worth the weight.
                                    */}
                                    <TableCell
                                        className={
                                            sync.rejected > 0
                                                ? 'text-right tabular-nums'
                                                : 'text-muted-foreground text-right tabular-nums'
                                        }
                                    >
                                        {sync.rejected.toLocaleString()}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            {(pagination.previous || pagination.next) && (
                <div className="mt-6 flex justify-end gap-2">
                    {pagination.previous && (
                        <Button variant="outline" asChild>
                            <Link href={pagination.previous} only={PARTIAL} preserveScroll>
                                Previous
                            </Link>
                        </Button>
                    )}
                    {pagination.next && (
                        <Button variant="outline" asChild>
                            <Link href={pagination.next} only={PARTIAL} preserveScroll>
                                Next
                            </Link>
                        </Button>
                    )}
                </div>
            )}
        </AppLayout>
    );
}

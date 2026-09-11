import { Link, router } from '@inertiajs/react';
import { EmptyState } from '@/components/empty-state';
import { LedgerLockControl } from '@/components/ledger-lock';
import { PageHeader } from '@/components/page-header';
import { Who2 } from '@/components/workday-cells';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusPill } from '@/components/ui/badge';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { manilaToday } from '@/lib/dates';
import { formatMinutes } from '@/lib/minutes';
import { index, show } from '@/routes/ledgers';
import { index as timelogsIndex } from '@/routes/timelogs';
import type { Ledger } from '@/types';

interface Filters {
    month: string;
}

interface Pagination {
    from: number | null;
    to: number | null;
    total: number;
    previous: string | null;
    next: string | null;
}

interface LedgerRow extends Ledger {
    workdays_count: number;
    worked: number;
    tardy: number;
    undertime: number;
}

/** Only the props the list owns. */
const PARTIAL = ['ledgers', 'pagination', 'filters'];

const COLUMNS = {
    days: 80,
    worked: 90,
    tardy: 90,
    undertime: 100,
    status: 170,
    actions: 110,
} as const;

/** What the flexible Person column needs for a full Filipino name. */
const FLEX_MIN = 240;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

function query(filters: Filters): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.month !== manilaToday().slice(0, 7)) {
        params.month = filters.month;
    }

    return params;
}

export default function Index({
    ledgers,
    pagination,
    filters,
}: {
    ledgers: LedgerRow[];
    pagination: Pagination;
    filters: Filters;
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
                title="Ledgers"
                description="Whose month is done, and whose is still open."
                month={{ value: filters.month, onChange: (month) => go({ month }) }}
            />

            {ledgers.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No ledgers this month"
                        description="A ledger appears once the engine has computed a workday for someone this month. Import punches from Timelogs to start the month."
                        action={
                            <Button variant="outline" asChild>
                                <Link href={timelogsIndex()}>Go to Timelogs</Link>
                            </Button>
                        }
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>This month</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {pagination.from}–{pagination.to} of {pagination.total.toLocaleString()}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Employee-months this period. A locked row has dropped its ink; the pill still says Locked.
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead>Person</TableHead>
                                <TableHead style={{ width: COLUMNS.days }} numeric>
                                    Days
                                </TableHead>
                                <TableHead style={{ width: COLUMNS.worked }} numeric>
                                    Worked
                                </TableHead>
                                <TableHead style={{ width: COLUMNS.tardy }} numeric>
                                    Tardy
                                </TableHead>
                                <TableHead style={{ width: COLUMNS.undertime }} numeric>
                                    Undertime
                                </TableHead>
                                <TableHead style={{ width: COLUMNS.status }}>Status</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ledgers.map((ledger) => {
                                const locked = ledger.locked_at !== null;

                                return (
                                    <TableRow
                                        key={ledger.id}
                                        className={cn(
                                            'cursor-pointer',
                                            locked && 'locked [&>td]:text-muted-foreground',
                                        )}
                                        onClick={() => router.visit(show.url(ledger))}
                                    >
                                        <TableCell className="h-[52px] max-w-0">
                                            <Link
                                                href={show.url(ledger)}
                                                className="hover:text-acc-text block min-w-0"
                                                onClick={(event) => event.stopPropagation()}
                                            >
                                                <Who2 employee={ledger.employee} />
                                            </Link>
                                        </TableCell>
                                        <TableCell numeric>{ledger.workdays_count}</TableCell>
                                        <TableCell numeric>{formatMinutes(ledger.worked)}</TableCell>
                                        <TableCell numeric>{formatMinutes(ledger.tardy)}</TableCell>
                                        <TableCell numeric>{formatMinutes(ledger.undertime)}</TableCell>
                                        <TableCell>
                                            {locked ? (
                                                <StatusPill variant="positive">Locked</StatusPill>
                                            ) : (
                                                <StatusPill variant="attention">Waiting for lock</StatusPill>
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className="text-right"
                                            onClick={(event) => event.stopPropagation()}
                                        >
                                            <LedgerLockControl ledger={ledger} />
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
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

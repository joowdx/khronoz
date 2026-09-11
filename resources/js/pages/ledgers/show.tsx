import { router, usePage } from '@inertiajs/react';
import { LedgerLockControl } from '@/components/ledger-lock';
import { PageHeader } from '@/components/page-header';
import { MinuteCells, PunchChain, WorkdayStatus } from '@/components/workday-cells';
import { Field } from '@/components/field';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { addDay, formatDayWithWeekday } from '@/lib/dates';
import { formatMinutes } from '@/lib/minutes';
import { formatMonthTitle } from '@/components/month-stepper';
import { index, show } from '@/routes/ledgers';
import type { Choice, Ledger, LedgerView, Workday } from '@/types';

const PARTIAL = ['view'];

const COLUMNS = {
    shift: 140,
    status: 200,
    chain: 240,
    worked: 80,
    tardy: 80,
    undertime: 100,
    excess: 80,
    night: 80,
} as const;

/** Date carries the weekday; it is the one flexible column on this page. */
const FLEX_MIN = 200;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

function paramsFromUrl(url: string): URLSearchParams {
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';

    return new URLSearchParams(query);
}

/**
 * Dates in the selected period, inclusive, as `YYYY-MM-DD`.
 *
 * `first` is days 1–15, `second` is 16 through the last day, anything else
 * (including the default whole month) is the full run. Built from the
 * ledger's own month string so a missing workday still occupies its row —
 * a gap in the middle of a month is information.
 */
function datesInPeriod(monthStart: string, period: string): string[] {
    const last = lastDayOfMonth(monthStart);
    let cursor = period === 'second' ? `${monthStart.slice(0, 8)}16` : monthStart;
    const end = period === 'first' ? `${monthStart.slice(0, 8)}15` : last;
    const dates: string[] = [];

    if (cursor > end) {
        return dates;
    }

    while (cursor <= end) {
        dates.push(cursor);
        cursor = addDay(cursor);
    }

    return dates;
}

function lastDayOfMonth(monthStart: string): string {
    const [year, month] = monthStart.split('-').map(Number);

    if (year === undefined || month === undefined || Number.isNaN(year + month)) {
        return monthStart;
    }

    const last = new Date(Date.UTC(year, month, 0)).getUTCDate();

    return `${year}-${String(month).padStart(2, '0')}-${String(last).padStart(2, '0')}`;
}

export default function Show({
    ledger,
    view,
    periods,
    works,
}: {
    ledger: Ledger;
    view: LedgerView;
    periods: Choice[];
    works: Choice[];
}) {
    const { url } = usePage();
    const params = paramsFromUrl(url);
    const requestedPeriod = params.get('period') ?? '';
    const requestedWork = params.get('work') ?? '';
    const period =
        periods.find((choice) => choice.value === requestedPeriod)?.value ??
        periods.find((choice) => choice.value === 'full')?.value ??
        periods[0]?.value ??
        '';
    const work = works.find((choice) => choice.value === requestedWork)?.value ?? '';

    function go(next: { period?: string; work?: string }) {
        const nextPeriod = next.period ?? period;
        const nextWork = next.work === undefined ? work : next.work;
        const query: Record<string, string> = {};

        if (nextPeriod !== '' && nextPeriod !== 'full') {
            query.period = nextPeriod;
        }

        if (nextWork !== '') {
            query.work = nextWork;
        }

        router.get(show.url(ledger), query, {
            only: PARTIAL,
            preserveState: true,
            preserveScroll: true,
        });
    }

    const byDate = new Map(view.workdays.map((workday) => [workday.date, workday]));
    const dates = datesInPeriod(ledger.month, period);
    const monthLabel = formatMonthTitle(ledger.month.slice(0, 7));

    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{
                    title: 'Ledgers',
                    href: index.url({ query: { month: ledger.month.slice(0, 7) } }),
                }}
                title={ledger.employee?.name ?? 'Ledger'}
                description={monthLabel}
                actions={<LedgerLockControl ledger={ledger} />}
            />

            <div className="mb-6 flex flex-wrap items-end gap-3">
                <Field label="Period" htmlFor="period">
                    {({ id }) => (
                        <ToggleGroup
                            id={id}
                            type="single"
                            value={period}
                            onValueChange={(value) => {
                                if (value !== '') {
                                    go({ period: value });
                                }
                            }}
                            variant="outline"
                            aria-label="Period"
                        >
                            {periods.map((choice) => (
                                <ToggleGroupItem key={choice.value} value={choice.value}>
                                    {choice.label}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    )}
                </Field>
                <Field label="Work" htmlFor="work">
                    {({ id }) => (
                        <ToggleGroup
                            id={id}
                            type="single"
                            value={work === '' ? 'all' : work}
                            onValueChange={(value) => {
                                if (value !== '') {
                                    go({ work: value === 'all' ? '' : value });
                                }
                            }}
                            variant="outline"
                            aria-label="Work"
                        >
                            {works.map((choice) => (
                                <ToggleGroupItem key={choice.value} value={choice.value}>
                                    {choice.label}
                                </ToggleGroupItem>
                            ))}
                            <ToggleGroupItem value="all">All</ToggleGroupItem>
                        </ToggleGroup>
                    )}
                </Field>
            </div>

            <dl className="grid auto-cols-fr grid-flow-col pb-8">
                <Figure value={view.worked} label="Worked" />
                <Figure value={view.tardy} label="Tardy" />
                <Figure value={view.undertime} label="Undertime" />
                <Figure value={view.overtime} label="Overtime" />
                <Figure value={view.night} label="Night" />
            </dl>

            <Card className="min-w-min overflow-visible">
                <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                    <TableCaption className="sr-only mt-0">
                        Daily time record for {ledger.employee?.name ?? 'this employee'}, {monthLabel}. Every date in
                        the period is a row, including days with no workday.
                    </TableCaption>
                    <TableHeader sticky>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead style={{ width: COLUMNS.shift }}>Shift</TableHead>
                            <TableHead style={{ width: COLUMNS.status }}>Status</TableHead>
                            <TableHead style={{ width: COLUMNS.chain }}>Chain</TableHead>
                            <TableHead style={{ width: COLUMNS.worked }} numeric>
                                Worked
                            </TableHead>
                            <TableHead style={{ width: COLUMNS.tardy }} numeric>
                                Tardy
                            </TableHead>
                            <TableHead style={{ width: COLUMNS.undertime }} numeric>
                                Undertime
                            </TableHead>
                            <TableHead style={{ width: COLUMNS.excess }} numeric>
                                Excess
                            </TableHead>
                            <TableHead style={{ width: COLUMNS.night }} numeric>
                                Night
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {dates.map((date) => (
                            <DayRow key={date} date={date} workday={byDate.get(date) ?? null} />
                        ))}
                    </TableBody>
                </Table>
            </Card>

            <dl className="mt-8 max-w-lg">
                <Fact label="Tardy occurrences" value={view.tardy_occurrences} />
                <Fact label="Undertime occurrences" value={view.undertime_occurrences} />
                <Fact label="Absences" value={view.absences} />
            </dl>
        </AppLayout>
    );
}

function DayRow({ date, workday }: { date: string; workday: Workday | null }) {
    return (
        <TableRow>
            <TableCell className="tabular-nums">{formatDayWithWeekday(date)}</TableCell>
            <TableCell>
                {workday?.shift_name ?? <span className="text-muted-foreground">—</span>}
            </TableCell>
            <TableCell>
                {workday ? (
                    <WorkdayStatus status={workday.status} premium={workday.premium} />
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
            <TableCell>
                <PunchChain punches={workday?.punches} />
            </TableCell>
            <MinuteCells workday={workday} />
        </TableRow>
    );
}

function Figure({ value, label }: { value: number; label: string }) {
    return (
        <div className="border-border flex flex-col-reverse border-l pt-3.5 pb-0.5 pl-5 first:border-l-0 first:pl-0">
            <dt className="text-muted-foreground pt-[3px] text-[13px] leading-[18px]">{label}</dt>
            <dd className="text-2xl leading-[30px] font-bold tracking-[-0.011em] tabular-nums">
                {formatMinutes(value)}
            </dd>
        </div>
    );
}

function Fact({ label, value }: { label: string; value: number }) {
    return (
        <div className="[&+&]:border-rule flex min-h-[38px] items-baseline gap-2 pt-[9px] [&+&]:border-t">
            <dt className="shrink-0 text-sm leading-5">{label}</dt>
            <span
                aria-hidden
                className="mx-0.5 h-px min-w-4 flex-1 self-center bg-[radial-gradient(circle_at_1px_1px,var(--dot)_1px,transparent_1.2px)] bg-[length:5px_2px] bg-repeat-x"
            />
            <dd className="text-right text-sm leading-5 font-semibold tabular-nums">{value}</dd>
        </div>
    );
}

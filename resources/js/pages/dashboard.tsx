import { Link, router, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import { LaneChart, useNowOn } from '@/components/lane-chart';
import { formatMonthTitle } from '@/components/month-stepper';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Who2 } from '@/components/workday-cells';
import { useCan } from '@/hooks/use-can';
import { useManilaClock } from '@/hooks/use-manila-clock';
import AppLayout from '@/layouts/app-layout';
import { manilaToday } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as ledgersIndex } from '@/routes/ledgers';
import { create as createAgency, index as agenciesIndex } from '@/routes/platform/agencies';
import { index as rostersIndex } from '@/routes/rosters';
import { index as timelogsIndex } from '@/routes/timelogs';
import { create as inviteUser, index as usersIndex } from '@/routes/users';
import { index as workdaysIndex } from '@/routes/workdays';
import type { Choice, Employee, SharedProps } from '@/types';

interface DashboardCounts {
    users: number;
    active: number;
    invited: number;
    agencies?: number;
    agency_users?: number;
    empty_agencies?: number;
    unresolved_timelogs?: number;
    without_roster?: number;
}

interface MonthWindow {
    value: string;
    previous: string;
    through: string | null;
    days: number;
}

interface Comparison {
    value: number;
    previous: number;
}

interface Figures {
    workdays: Comparison;
    tardy: Comparison;
    undertime: Comparison;
    absent: Comparison;
    overtime: Comparison;
}

interface LedgerSplit {
    total: number;
    open: number;
    lockable: number;
    locked: number;
    attested: number;
}

interface DayEvent {
    id: string;
    kind: Choice;
    employee: Employee | null;
    detail: string | null;
    time: string;
}

interface Today {
    total: number;
    events: DayEvent[];
}

interface NightOut {
    id: string;
    employee: Employee | null;
    shift: string | null;
    since: string;
}

interface Tardiness {
    total: number;
    workgroups: { id: string; name: string; count: number }[];
}

interface Duty {
    date: string;
    lanes: {
        id: string;
        name: string;
        slot: number;
        count: number;
        bars: { from: number; to: number; label: string }[];
    }[];
}

function Stack({ children }: { children: ReactNode }) {
    return <div className="[&>*+*]:border-border [&>*+*]:mt-8 [&>*+*]:border-t [&>*+*]:pt-8">{children}</div>;
}

function Split({ width, children, right }: { width: number; children: ReactNode; right: ReactNode }) {
    return (
        <div
            className="grid gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,var(--split-right))]"
            style={{ '--split-right': `${width}px` } as CSSProperties}
        >
            <div className="min-w-0">{children}</div>
            <div className="border-border min-w-0 lg:border-l lg:pl-12">{right}</div>
        </div>
    );
}

function SectionHead({ title, meta }: { title: string; meta?: string }) {
    return (
        <div className="border-border flex items-baseline gap-3 border-b pb-3">
            <h2 className="text-sm leading-5 font-semibold tracking-[-0.002em]">{title}</h2>
            {meta && (
                <span className="text-muted-foreground ml-auto text-xs leading-4 font-medium tabular-nums">{meta}</span>
            )}
        </div>
    );
}

function Dots() {
    return (
        <span
            aria-hidden
            className="mx-0.5 h-px min-w-4 flex-1 self-center bg-[radial-gradient(circle_at_1px_1px,var(--dot)_1px,transparent_1.2px)] bg-[length:5px_2px] bg-repeat-x"
        />
    );
}

function AttentionRow({
    label,
    count,
    href,
    divider,
}: {
    label: string;
    count: number;
    href: string;
    divider: boolean;
}) {
    return (
        <Link
            href={href}
            className={cn(
                'group/row hover:bg-row-hover relative -mx-2.5 flex min-h-11 items-center gap-2 rounded-lg px-2.5',
                divider &&
                    "before:bg-rule before:absolute before:inset-x-2.5 before:top-0 before:h-px before:content-['']",
            )}
        >
            <span className="text-sm leading-5">{label}</span>
            <Dots />
            <span className="text-attention text-lg leading-6 font-semibold tabular-nums">{count}</span>
            <ChevronRight
                aria-hidden
                strokeWidth={1.5}
                className="text-input group-hover/row:text-acc-text ml-2.5 size-3.5 shrink-0"
            />
        </Link>
    );
}

function Fact({ label, value, tone }: { label: string; value: number; tone?: 'attention' }) {
    return (
        <div className="flex min-h-[38px] items-baseline gap-2 pt-[9px]">
            <dt className="flex min-w-0 flex-1 items-baseline gap-2 text-sm leading-5">
                {label}
                <Dots />
            </dt>
            <dd
                className={cn('text-sm leading-5 font-semibold tabular-nums', tone === 'attention' && 'text-attention')}
            >
                {value}
            </dd>
        </div>
    );
}

function Figure({
    value,
    label,
    previous,
    comparison,
    tone = 'fault',
}: {
    value: number;
    label: string;
    previous?: number;
    comparison?: string;
    tone?: 'fault' | 'neutral';
}) {
    const delta = previous === undefined ? null : value - previous;

    return (
        <div className="border-border flex flex-col border-l pt-3.5 pb-0.5 pl-5 first:border-l-0 first:pl-0">
            <dt className="text-muted-foreground order-2 pt-[3px] text-[13px] leading-[18px]">{label}</dt>
            <dd className="order-1 text-2xl leading-[30px] font-bold tracking-[-0.011em] tabular-nums">{value}</dd>
            {delta !== null && comparison !== undefined && (
                <dd
                    className={cn(
                        'text-muted-foreground order-3 pt-[7px] text-xs leading-4 font-medium tabular-nums',
                        tone === 'fault' && delta > 0 && 'text-destructive',
                        tone === 'fault' && delta < 0 && 'text-positive',
                    )}
                >
                    {delta !== 0 && (
                        <span aria-hidden className="pr-1 text-[9px] leading-4">
                            {delta > 0 ? '▲' : '▼'}
                        </span>
                    )}
                    {delta === 0
                        ? `Same as ${comparison}`
                        : `${Math.abs(delta)} ${delta > 0 ? 'more' : 'fewer'} than ${comparison}`}
                </dd>
            )}
        </div>
    );
}

function NightOutRow({ row }: { row: NightOut }) {
    return (
        <div className="flex min-h-[52px] items-center gap-2.5 py-2">
            <span className="min-w-0 flex-1">
                <Who2 employee={row.employee} />
            </span>
            <span className="text-right text-[13px] leading-[18px] font-medium whitespace-nowrap tabular-nums">
                {row.shift ?? '—'}
                <span className="text-attention block text-xs leading-4 font-normal">No out since {row.since}</span>
            </span>
        </div>
    );
}

function MeterRow({ label, count, largest }: { label: string; count: number; largest: number }) {
    return (
        <div className="flex min-h-[34px] items-center gap-3">
            <span className="w-[148px] shrink-0 truncate text-[13px] leading-[18px]">{label}</span>
            <span aria-hidden className="bg-rule h-2 min-w-0 flex-1 rounded-[2px]">
                <span
                    className="bg-acc-text block h-2 rounded-[2px]"
                    style={{ width: `${largest === 0 ? 0 : (count / largest) * 100}%` }}
                />
            </span>
            <span className="w-[26px] shrink-0 text-right text-[13px] leading-[18px] font-semibold tabular-nums">
                {count}
            </span>
        </div>
    );
}

function More({ href, children }: { href: string; children: ReactNode }) {
    return (
        <p className="pt-4">
            <Link
                href={href}
                className="text-acc-text text-[13px] leading-[18px] font-medium underline-offset-2 hover:underline"
            >
                {children}
            </Link>
        </p>
    );
}

function monthName(value: string): string {
    return formatMonthTitle(value).replace(/\s\d{4}$/, '');
}

const EVENT_TONE: Record<string, string> = {
    late_in: 'text-destructive',
    missed_out: 'text-attention',
    leave: 'text-muted-foreground',
    overtime: 'text-positive',
};

export default function Dashboard({
    counts,
    month,
    figures,
    ledgers,
    today,
    night_outs: nightOuts,
    tardiness,
    duty,
}: {
    counts: DashboardCounts;
    month?: MonthWindow;
    figures?: Figures;
    ledgers?: LedgerSplit;
    today?: Today;
    night_outs?: NightOut[];
    tardiness?: Tardiness;
    duty?: Duty;
}) {
    const { agency } = usePage<SharedProps>().props;
    const can = useCan();
    const { stamp, date } = useManilaClock();

    const platform = agency?.platform ?? false;
    const manageUsers = can('users.manage');

    const now = useNowOn(duty?.date ?? '');

    function goToMonth(next: string) {
        router.get(dashboard.url(), next === manilaToday().slice(0, 7) ? {} : { month: next }, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    const attention = [
        ...(counts.unresolved_timelogs
            ? [
                  {
                      label: 'Unresolved timelogs',
                      count: counts.unresolved_timelogs,
                      href: timelogsIndex({ query: { unresolved: '1' } }).url,
                  },
              ]
            : []),
        ...(counts.without_roster
            ? [
                  {
                      label: 'Employees without a roster',
                      count: counts.without_roster,
                      href: rostersIndex().url,
                  },
              ]
            : []),
        ...(nightOuts && nightOuts.length > 0
            ? [
                  {
                      label: 'Workdays waiting for a night shift to clock out',
                      count: nightOuts.length,
                      href: workdaysIndex({ query: { attention: '1' } }).url,
                  },
              ]
            : []),
        ...(ledgers && month && ledgers.lockable > 0
            ? [
                  {
                      label: 'Ledgers waiting to be locked',
                      count: ledgers.lockable,
                      href: ledgersIndex({ query: { month: month.value } }).url,
                  },
              ]
            : []),
        ...(counts.invited > 0 && manageUsers
            ? [
                  {
                      label: 'Invitations not yet accepted',
                      count: counts.invited,
                      href: usersIndex({ query: { status: 'invited' } }).url,
                  },
              ]
            : []),
        ...(platform && (counts.empty_agencies ?? 0) > 0
            ? [
                  {
                      label: 'Agencies without users',
                      count: counts.empty_agencies ?? 0,
                      href: agenciesIndex().url,
                  },
              ]
            : []),
    ];

    const needsAttention = (
        <>
            <SectionHead title="Needs attention" />
            {attention.length === 0 ? (
                <p className="text-muted-foreground pt-3 text-sm leading-5">Nothing is waiting on you.</p>
            ) : (
                attention.map((row, index) => <AttentionRow key={row.label} {...row} divider={index > 0} />)
            )}
        </>
    );

    const barelyStarted = platform ? counts.agencies === 0 : counts.users <= 1;
    const nextStep = platform ? (
        <EmptyState
            className="py-0"
            title="Add your first agency"
            description="Every agency keeps its own employees, schedules and daily time records. Add one, then enter it to set it up."
            action={
                <Button asChild>
                    <Link href={createAgency()}>Add agency</Link>
                </Button>
            }
        />
    ) : (
        manageUsers && (
            <EmptyState
                className="py-0"
                title="Invite your first colleague"
                description="Sign-in is by invitation. Invite the people in your office who keep the daily time records, and they choose their own password."
                action={
                    <Button asChild>
                        <Link href={inviteUser()}>Invite user</Link>
                    </Button>
                }
            />
        )
    );

    const largestTardiness = Math.max(0, ...(tardiness?.workgroups.map((workgroup) => workgroup.count) ?? []));

    const comparison = month !== undefined && month.through !== null ? monthName(month.previous) : undefined;

    return (
        <AppLayout>
            <PageHeader
                title="Dashboard"
                description={stamp}
                live
                month={month ? { value: month.value, onChange: goToMonth } : undefined}
            />
            <Stack>
                <section>
                    {ledgers && month ? (
                        <Split
                            width={420}
                            right={
                                <>
                                    <SectionHead title="Ledgers" meta={monthName(month.value)} />
                                    <dl className="[&>div+div]:border-rule [&>div+div]:border-t">
                                        <Fact label="Open" value={ledgers.open} />
                                        <Fact label="Waiting for lock" value={ledgers.lockable} tone="attention" />
                                        <Fact label="Locked" value={ledgers.locked} />
                                        <Fact label="Attested" value={ledgers.attested} />
                                    </dl>
                                    <More href={ledgersIndex({ query: { month: month.value } }).url}>
                                        Go to ledgers
                                    </More>
                                </>
                            }
                        >
                            {needsAttention}
                        </Split>
                    ) : (
                        needsAttention
                    )}
                </section>

                {figures && month && (
                    <section>
                        <SectionHead
                            title="This month, so far"
                            meta={
                                month.through === null
                                    ? `${monthName(month.value)} has not started`
                                    : `1 to ${Number(month.through.slice(8))} ${monthName(month.value)}, against the same ${month.days} ${month.days === 1 ? 'day' : 'days'} of ${monthName(month.previous)}`
                            }
                        />
                        <dl className="grid auto-cols-fr grid-flow-col pt-1">
                            <Figure
                                value={figures.workdays.value}
                                previous={figures.workdays.previous}
                                comparison={comparison}
                                tone="neutral"
                                label="Workdays computed"
                            />
                            <Figure
                                value={figures.tardy.value}
                                previous={figures.tardy.previous}
                                comparison={comparison}
                                label="Tardy occurrences"
                            />
                            <Figure
                                value={figures.undertime.value}
                                previous={figures.undertime.previous}
                                comparison={comparison}
                                label="Undertime occurrences"
                            />
                            <Figure
                                value={figures.absent.value}
                                previous={figures.absent.previous}
                                comparison={comparison}
                                label="Absent, unexcused"
                            />
                            <Figure
                                value={figures.overtime.value}
                                previous={figures.overtime.previous}
                                comparison={comparison}
                                tone="neutral"
                                label="Overtime authorised"
                            />
                        </dl>
                    </section>
                )}

                {duty && (
                    <section>
                        <SectionHead
                            title={now === null ? 'On duty' : `On duty now, ${now.time}`}
                            meta="Counts come from the resolved shift, not from punches"
                        />
                        <div className="pt-4">
                            <LaneChart lanes={duty.lanes} now={now} />
                        </div>
                    </section>
                )}

                {today && (
                    <section>
                        <Split
                            width={360}
                            right={
                                <>
                                    {nightOuts && (
                                        <>
                                            <SectionHead
                                                title="Pending night outs"
                                                meta={
                                                    nightOuts.length === 1
                                                        ? '1 employee'
                                                        : `${nightOuts.length} employees`
                                                }
                                            />
                                            {nightOuts.length === 0 ? (
                                                <p className="text-muted-foreground pt-3 text-sm leading-5">
                                                    Every shift that has ended has clocked out.
                                                </p>
                                            ) : (
                                                <div className="[&>div+div]:border-rule [&>div+div]:border-t">
                                                    {nightOuts.map((row) => (
                                                        <NightOutRow key={row.id} row={row} />
                                                    ))}
                                                </div>
                                            )}
                                        </>
                                    )}

                                    {tardiness && (
                                        <div className="pt-8">
                                            <SectionHead
                                                title="Tardiness by workgroup"
                                                meta={`${tardiness.total} so far`}
                                            />
                                            {tardiness.workgroups.length === 0 ? (
                                                <p className="text-muted-foreground pt-3 text-sm leading-5">
                                                    Nobody has been late this month.
                                                </p>
                                            ) : (
                                                <div className="pt-2.5">
                                                    {tardiness.workgroups.map((workgroup) => (
                                                        <MeterRow
                                                            key={workgroup.id}
                                                            label={workgroup.name}
                                                            count={workgroup.count}
                                                            largest={largestTardiness}
                                                        />
                                                    ))}
                                                </div>
                                            )}
                                            <More href={workdaysIndex().url}>Go to workdays</More>
                                        </div>
                                    )}
                                </>
                            }
                        >
                            <SectionHead
                                title={`Today, ${Number(date.slice(8))} ${monthName(date.slice(0, 7))}`}
                                meta={today.total === 1 ? '1 event' : `${today.total} events`}
                            />
                            {today.events.length === 0 ? (
                                <p className="text-muted-foreground pt-3 text-sm leading-5">
                                    Nothing out of the ordinary has been recorded today.
                                </p>
                            ) : (
                                <Table className="[&_td:first-child]:pl-0 [&_td:last-child]:pr-0 [&_th:first-child]:pl-0 [&_th:last-child]:pr-0">
                                    <TableCaption className="sr-only mt-0">
                                        What has been recorded today, earliest first.
                                    </TableCaption>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-[186px]">What happened</TableHead>
                                            <TableHead>Employee</TableHead>
                                            <TableHead>Detail</TableHead>
                                            <TableHead className="w-[132px]" numeric>
                                                Time
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {today.events.map((event) => (
                                            <TableRow key={event.id}>
                                                <TableCell>
                                                    <span
                                                        className={cn(
                                                            'inline-flex items-center gap-[7px] text-[13px] leading-[18px] font-medium',
                                                            EVENT_TONE[event.kind.value],
                                                        )}
                                                    >
                                                        <span
                                                            aria-hidden
                                                            className="size-1.5 shrink-0 rounded-[2px] bg-current"
                                                        />
                                                        {event.kind.label}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="h-[52px] max-w-0">
                                                    <Who2 employee={event.employee} />
                                                </TableCell>
                                                <TableCell className="text-muted-foreground max-w-0 truncate text-[13px]">
                                                    {event.detail ?? '—'}
                                                </TableCell>
                                                <TableCell numeric>{event.time}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </Split>
                    </section>
                )}

                <section>
                    {platform ? (
                        <>
                            <SectionHead
                                title="Across the platform"
                                meta="Superusers belong to khronoz itself, not to an agency"
                            />
                            <dl className="grid auto-cols-fr grid-flow-col pt-1">
                                <Figure value={counts.agencies ?? 0} label="Agencies" />
                                <Figure value={counts.agency_users ?? 0} label="Users across agencies" />
                                <Figure value={counts.users} label="Superusers" />
                            </dl>
                        </>
                    ) : (
                        <>
                            <SectionHead title="Who can sign in" meta="Sign-in is by invitation" />
                            <dl className="grid auto-cols-fr grid-flow-col pt-1">
                                <Figure value={counts.users} label="Users" />
                                <Figure value={counts.active} label="Active" />
                                <Figure value={counts.invited} label="Invited" />
                            </dl>
                        </>
                    )}
                </section>

                {barelyStarted && nextStep && <section>{nextStep}</section>}
            </Stack>
        </AppLayout>
    );
}

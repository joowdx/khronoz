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

/**
 * The signed-in landing page, composed for the data that exists.
 *
 * It is the artboard (docs/design/mockups/03-dashboard.html): what needs
 * attention beside where the month's ledgers stand, the five-figure strip
 * against the same days of the month before, the lane chart of who is on duty
 * now, today's events beside the pending night outs and tardiness by
 * workgroup. Sections are separated by a rule and never chopped into cards
 * (§1 rule 3); the only bordered panel on the page is the lane chart's.
 *
 * **Every section is optional, and an absent one is absent from the props.**
 * `DashboardController` gates each on the right that owns its screen and drops
 * it entirely for the platform tenant, whose agency may hold no employees at
 * all — so a section this page does not render is one the viewer either may
 * not see or could not have. That is different from a section reporting zero,
 * which means measured and empty, and the two must never look alike.
 *
 * **Per-page shapes live here**, not in `types/index.d.ts`: the counts, the
 * figure pairs and the lane data are this page's payload and no resource's
 * (.ai/rules/resources.md). `Employee` and `Choice` are shared and come from
 * there.
 *
 * The page is month-scoped, so §5.2's stepper replaces the heading-row title
 * and the month lives in the query string — the dashboard is a link, and the
 * current month is dropped from it.
 */
interface DashboardCounts {
    users: number;
    active: number;
    invited: number;
    /** Platform tenant only; see DashboardController. */
    agencies?: number;
    agency_users?: number;
    empty_agencies?: number;
    /**
     * An agency tenant, and only where the viewer holds the right that owns
     * the screen behind the row — `terminals.view` and `scheduling.view`
     * respectively. Absent means "not yours to see", never zero.
     *
     * The other two attention rows have no count here on purpose: the pending
     * night outs are `night_outs.length` and the lockable ledgers are
     * `ledgers.lockable`, both of which this page is already sent. A second
     * copy of a number is a number that can disagree with itself.
     */
    unresolved_timelogs?: number;
    without_roster?: number;
}

/** The window every figure below is measured over. `through` is null for a month that has not started. */
interface MonthWindow {
    /** `YYYY-MM`. */
    value: string;
    /** `YYYY-MM`, the month the comparison is against. */
    previous: string;
    /** `YYYY-MM-DD`, the last day that has happened. */
    through: string | null;
    days: number;
}

/** One headline figure and the same days of the month before. */
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

/** The month's ledgers as a partition: the four add up to `total`. */
interface LedgerSplit {
    total: number;
    /** Not locked, and a punch of the month is still due — `ledgers_lock_complete` would refuse it. */
    open: number;
    /** Not locked, and nothing due: the lock button will accept these right now. */
    lockable: number;
    locked: number;
    attested: number;
}

/**
 * One thing that happened today. `kind` carries its own words from PHP, so
 * this page holds no vocabulary of its own and colours by the value alone.
 */
interface DayEvent {
    id: string;
    kind: Choice;
    employee: Employee | null;
    detail: string | null;
    /** `08:21`, or `17:00 – 20:00`, or `Whole day` — already read for the screen. */
    time: string;
}

interface Today {
    total: number;
    events: DayEvent[];
}

/** A night shift that should have clocked out this morning and has not. */
interface NightOut {
    id: string;
    employee: Employee | null;
    /** `22:00 – 06:00`, from the workday's frozen snapshot; null if it had no slots. */
    shift: string | null;
    since: string;
}

interface Tardiness {
    total: number;
    workgroups: { id: string; name: string; count: number }[];
}

/**
 * The lane chart's data (§5.22). Bars are **minutes from 06:00** on the
 * 06:00 → 30:00 window, so the drawing divides by 1440 at whatever width it
 * has and nothing was rounded on the wire. `slot` is `shifts.color`, 1 to 8.
 */
interface Duty {
    /** `YYYY-MM-DD` — the open day window's date, not necessarily the calendar date. */
    date: string;
    lanes: {
        id: string;
        name: string;
        slot: number;
        count: number;
        bars: { from: number; to: number; label: string }[];
    }[];
}

/** Sections are separated by a rule with 32 either side, never by a box (§1 rule 3). */
function Stack({ children }: { children: ReactNode }) {
    return <div className="[&>*+*]:border-border [&>*+*]:mt-8 [&>*+*]:border-t [&>*+*]:pt-8">{children}</div>;
}

/**
 * Two subjects side by side in one section (§6.3, `.split`): a 48 gutter, and
 * the right column carries the rule and the padding. It stacks below `lg`,
 * where 420 beside anything leaves neither column readable — and then the rule
 * would be drawn on the wrong edge, so it is scoped to the same breakpoint.
 */
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

/**
 * The leader between a label and its figure (§6.3, `.kv .dots`). A repeating
 * radial gradient rather than a border-dotted rule, which draws squares at 1px
 * and cannot be spaced.
 */
function Dots() {
    return (
        <span
            aria-hidden
            className="mx-0.5 h-px min-w-4 flex-1 self-center bg-[radial-gradient(circle_at_1px_1px,var(--dot)_1px,transparent_1.2px)] bg-[length:5px_2px] bg-repeat-x"
        />
    );
}

/**
 * A figure that is a link to the work behind it: 44 high, a dot leader, the
 * count at 18/24/600, and a chevron that takes the accent on hover (§6.3,
 * `.kv--nav`). The negative margins let the hover tint bleed past the column,
 * and the hairline between rows is a pseudo-element rather than a border so
 * the first row does not carry one.
 */
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

/**
 * A label and one figure, read as a list (§6.3, `.kv`): 38 min-height, the
 * hairline drawn by the list between rows so the first carries none, and the
 * value right at 14/20/600 `tabular-nums`.
 *
 * Not a link, unlike `AttentionRow`. Four rows of one subject pointing at one
 * destination read as four invitations to do the same thing; the section's own
 * link below says it once.
 *
 * The leader lives **inside the `<dt>`** rather than between it and the `<dd>`:
 * a `<div>` group in a `<dl>` may hold only `dt`s followed by `dd`s, so a bare
 * `<span>` in the middle is invalid markup for the sake of a decoration that
 * says nothing.
 */
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

/**
 * Up to five headline figures across a section, divided by rules and not by
 * cards (§6.3, `.figs`).
 *
 * The third line is the delta against the same days of the month before, and
 * its colour is **not sentiment about the direction** — §6.3 puts `.up` in
 * fault and `.down` in positive because the figures that carry a delta are
 * counts of things going wrong, and more tardiness is worse. A figure that is
 * merely volume (how many workdays were computed, how much overtime was
 * authorised) takes `tone="neutral"` and stays muted: reading more work done
 * as a failure would be a lie the palette told by itself.
 *
 * No delta at all when `previous` is absent — the platform strip has no month
 * to compare against, and a month that has not started has no days.
 */
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
        // A name/value pair, so it is marked up as one, and the DOM order is
        // the reading order: "Tardy occurrences, 36, 8 fewer than August" is
        // what a screen reader should hear. A <dl> group may not put a <dd>
        // before its <dt>, so the visual order — figure on top, then the
        // label, then the delta — is `order`, not source order. (This was
        // `flex-col-reverse` while there were only two lines; a third at the
        // bottom cannot be expressed by reversing, and putting the delta
        // first in source to fake it is exactly the invalid <dl> above.)
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

/**
 * A count that has people in it (§6.3, `.nite`): the person as the shell draws
 * them everywhere else, and the fact right-aligned with its qualifier beneath
 * in `--attn`.
 */
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

/**
 * A distribution across named buckets (§6.3, `.byu`): a 148 label, an 8px
 * trough filled in `--acc-text`, and the count at the right.
 *
 * The bar is scaled against the **largest** bucket rather than the total, so
 * the leading row always fills the trough and the shape of the distribution is
 * what the eye compares. The number beside it is the value; the bar is
 * `aria-hidden` because it says nothing the number does not.
 */
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

/** The plain `Go to …` link under a section's own list (§6.2). */
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

/** `2026-09` as `September`, through the one place month names are written. */
function monthName(value: string): string {
    return formatMonthTitle(value).replace(/\s\d{4}$/, '');
}

/** The event kinds, coloured by what they mean (§7): a fault, something waiting, a fact, a grant. */
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
    /**
     * Read twice: by the lane chart below, and by `components/day-strip.tsx`
     * through `usePage()` — the sidebar is outside this component and takes no
     * props of its own.
     */
    duty?: Duty;
}) {
    const { agency } = usePage<SharedProps>().props;
    const can = useCan();
    const { stamp, date } = useManilaClock();

    const platform = agency?.platform ?? false;
    const manageUsers = can('users.manage');

    // Null once the day window this was computed for has closed — a dashboard
    // left open past 06:00 would otherwise draw this morning's marker across
    // last night's roster. Called unconditionally because it is a hook; with
    // no `duty` the dates cannot match and it answers null, which is correct.
    const now = useNowOn(duty?.date ?? '');

    // The month is a filter, so it lives in the query string and the default
    // is dropped: an unfiltered dashboard is `/dashboard` and nothing else.
    function goToMonth(next: string) {
        router.get(dashboard.url(), next === manilaToday().slice(0, 7) ? {} : { month: next }, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    // Only rows the viewer can actually follow: a row that leads to a 403 is
    // worse than no row (§5.20, "nothing you may see"), and the controller
    // makes that decision by omitting the count outright rather than sending a
    // zero. Here the two collapse into one guard on purpose — a section the
    // viewer may not see and a count of nothing both mean *no row*, because
    // this list is what is waiting on somebody and nothing is. Where the
    // difference does matter, it is the section that shows it: the figure
    // strip reports its zeros and is absent when it may not be read.
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
                      // The workdays list's own attention filter is exactly
                      // this shape of day: a status of absent, or a punch with
                      // no side yet.
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

    // A tenant that has barely started gets one sentence and the one action
    // that matters, instead of a screen of zeros.
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

    // The meters are scaled against the biggest bucket, read rather than
    // assumed: the controller does order them, but a chart whose first bar is
    // only full because of an ORDER BY somewhere else is a chart that breaks
    // silently when that ordering changes.
    const largestTardiness = Math.max(0, ...(tardiness?.workgroups.map((workgroup) => workgroup.count) ?? []));

    // No delta on a month that has not started: every figure would report zero
    // against the whole of the month before and five "same as" lines would be
    // saying nothing five times.
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

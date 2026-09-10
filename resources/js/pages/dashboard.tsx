import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import { useManilaClock } from '@/hooks/use-manila-clock';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { create as createAgency, index as agenciesIndex } from '@/routes/platform/agencies';
import { create as inviteUser, index as usersIndex } from '@/routes/users';
import type { SharedProps } from '@/types';

/**
 * The signed-in landing page, composed for the data that exists.
 *
 * The artboard (docs/design/mockups/03-dashboard.html) draws the finished
 * product: a lane chart of who is on duty now, a five-figure month strip with
 * an August comparison, today's events, pending night outs and tardiness by
 * workgroup. Every one of those needs a model Milestone 1 does not have, so none of
 * them is faked here:
 *
 * | Left out                                      | Needs                   | Milestone |
 * | --------------------------------------------- | ----------------------- | --------- |
 * | Month stepper as the title                    | month-scoped data       | 6         |
 * | Unresolved timelogs, employees with no roster | Timelog, Roster         | 5, 3      |
 * | This month, so far — and its comparison       | Workday                 | 6         |
 * | On duty now (the lane chart)                  | Shift, Schedule, Roster | 3 and 6   |
 * | Today's events, pending night outs            | Punch, Workday          | 6         |
 * | Ledgers, tardiness by workgroup                    | Ledger, Workgroup            | 6, 2      |
 *
 * What is left is real: who can sign in, who has not accepted an invitation
 * yet, and — for the platform tenant — how the estate is doing. The page is
 * short, and it is meant to be. Padding it with placeholder cards would make a
 * first-time agency think the product had nothing to show them.
 */
interface DashboardCounts {
    users: number;
    active: number;
    invited: number;
    /** Platform tenant only; see DashboardController. */
    agencies?: number;
    agency_users?: number;
    empty_agencies?: number;
}

/** Sections are separated by a rule with 32 either side, never by a box (§1 rule 3). */
function Stack({ children }: { children: ReactNode }) {
    return <div className="[&>*+*]:border-border [&>*+*]:mt-8 [&>*+*]:border-t [&>*+*]:pt-8">{children}</div>;
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
            <span
                aria-hidden
                className="mx-0.5 h-px min-w-4 flex-1 self-center bg-[radial-gradient(circle_at_1px_1px,var(--dot)_1px,transparent_1.2px)] bg-[length:5px_2px] bg-repeat-x"
            />
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
 * Up to five headline figures across a section, divided by rules and not by
 * cards (§6.3, `.figs`). The artboard gives each figure a third line — a delta
 * against the same days of last month. There is no history to compare against
 * yet, so that line is absent rather than filled with a qualifier the label
 * already implies; it returns with `Workday` in Milestone 6.
 */
function Figure({ value, label }: { value: number; label: string }) {
    return (
        // A name/value pair, so it is marked up as one. The label comes first
        // in the DOM — "Users, 1" is what a screen reader should hear, and a
        // <dl> group may not put its <dd> before its <dt> — and
        // `flex-col-reverse` puts the figure on top where the design wants it.
        <div className="border-border flex flex-col-reverse border-l pt-3.5 pb-0.5 pl-5 first:border-l-0 first:pl-0">
            <dt className="text-muted-foreground pt-[3px] text-[13px] leading-[18px]">{label}</dt>
            <dd className="text-2xl leading-[30px] font-bold tracking-[-0.011em] tabular-nums">{value}</dd>
        </div>
    );
}

export default function Dashboard({ counts }: { counts: DashboardCounts }) {
    const { agency } = usePage<SharedProps>().props;
    const can = useCan();
    const { stamp } = useManilaClock();

    const platform = agency?.platform ?? false;
    const manageUsers = can('users.manage');

    // Only rows the viewer can actually follow: a row that leads to a 403 is
    // worse than no row (§5.20, "nothing you may see").
    const attention = [
        ...(counts.invited > 0 && manageUsers
            ? [
                  {
                      label: 'Invitations not yet accepted',
                      count: counts.invited,
                      // Task 3 teaches the users list this filter. Until then
                      // the link lands on the full list, which is still where
                      // the work is done.
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

    return (
        <AppLayout>
            <PageHeader title="Dashboard" description={stamp} live />
            <Stack>
                <section>
                    <SectionHead title="Needs attention" />
                    {attention.length === 0 ? (
                        <p className="text-muted-foreground pt-3 text-sm leading-5">Nothing is waiting on you.</p>
                    ) : (
                        attention.map((row, index) => <AttentionRow key={row.label} {...row} divider={index > 0} />)
                    )}
                </section>

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

import { Form } from '@inertiajs/react';
import { CopyPlusIcon, RotateCcwIcon } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { OffBox, RemoteBox, ShiftChip, type Slot } from '@/components/shift-chip';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatMinutes } from '@/lib/minutes';
import { copy, refresh } from '@/routes/defaults';
import type { Schedule, Shift } from '@/types';

/**
 * One default and what this agency has of it.
 *
 * `copy` is the agency's own row for the default — the linked copy when there
 * is one, otherwise the row that merely shares the name, which is why
 * `linked` is separate. `differs` is only ever true for a linked row: an
 * unlinked namesake was never claimed to match the default, so calling it
 * changed would be a verdict on somebody else's work (DefaultController).
 */
interface Row<T> {
    default: T;
    copy: T | null;
    linked: boolean;
    differs: boolean;
}

/** The four states a row can be in, in the order the list resolves them. */
type State = 'missing' | 'own' | 'changed' | 'current';

const STATES: Record<State, { label: string; variant: 'outline' | 'secondary' | 'attention' | 'positive' }> = {
    missing: { label: 'Not copied', variant: 'outline' },
    own: { label: 'Your own', variant: 'secondary' },
    changed: { label: 'Changed', variant: 'attention' },
    current: { label: 'Up to date', variant: 'positive' },
};

const SHIFT_COLUMNS = {
    day: 280,
    credits: 110,
    state: 140,
    actions: 110,
} as const;

/** What the flexible Shift column needs for a real default name. */
const SHIFT_FLEX_MIN = 220;

const SHIFT_MIN_WIDTH = Object.values(SHIFT_COLUMNS).reduce((sum, width) => sum + width, 0) + SHIFT_FLEX_MIN;

const SCHEDULE_COLUMNS = {
    schedule: 240,
    days: 90,
    state: 140,
    actions: 110,
} as const;

/** What the flexible Cycle column needs for a week of chips and its overflow note. */
const SCHEDULE_FLEX_MIN = 260;

const SCHEDULE_MIN_WIDTH = Object.values(SCHEDULE_COLUMNS).reduce((sum, width) => sum + width, 0) + SCHEDULE_FLEX_MIN;

/** Chips past this are counted rather than drawn; a 21-day rotation is a legal cycle. */
const CYCLE_SHOWN = 14;

/**
 * The platform's shifts and schedules, and what this agency has of each
 * (04-scheduling.md rule 7).
 *
 * Copy is one action for the whole set and sits in the heading row, because
 * that is what it is: a schedule cannot be copied without the shifts its
 * turns name, so a per-row copy would silently drag other rows along.
 * Refresh is per row, and only offered where it can succeed — a linked copy
 * that has drifted. Everything else states a fact instead of inviting an
 * action that would do nothing.
 */
export default function Index({ shifts, schedules }: { shifts: Row<Shift>[]; schedules: Row<Schedule>[] }) {
    const can = useCan();
    const manage = can('scheduling.manage');

    const missing = [...shifts, ...schedules].filter((row) => row.copy === null).length;
    const empty = shifts.length === 0 && schedules.length === 0;

    return (
        <AppLayout>
            <PageHeader
                title="Defaults"
                description="Shifts and schedules khronoz keeps for every agency. Copy them once, then refresh a copy whenever the default moves on."
                actions={manage && missing > 0 ? <CopyButton missing={missing} /> : null}
            />

            {empty ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No defaults yet"
                        description="khronoz has not published any default shifts or schedules. Build your own under Shifts and Schedules — nothing here is waiting on you."
                    />
                </Card>
            ) : (
                <div className="flex flex-col gap-6">
                    <Card className="min-w-min overflow-visible">
                        <CardHeader>
                            <CardTitle>Shifts</CardTitle>
                            <CardDescription className="ml-auto tabular-nums">
                                {shifts.length} {shifts.length === 1 ? 'default' : 'defaults'}
                            </CardDescription>
                        </CardHeader>

                        <Table style={{ minWidth: SHIFT_MIN_WIDTH }}>
                            <TableCaption className="sr-only mt-0">
                                Default shifts, and what this agency has of each
                            </TableCaption>
                            <TableHeader sticky>
                                <TableRow>
                                    <TableHead>Shift</TableHead>
                                    <TableHead style={{ width: SHIFT_COLUMNS.day }}>Day</TableHead>
                                    <TableHead style={{ width: SHIFT_COLUMNS.credits }}>Credits</TableHead>
                                    <TableHead style={{ width: SHIFT_COLUMNS.state }}>Here</TableHead>
                                    <TableHead style={{ width: SHIFT_COLUMNS.actions }}>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {shifts.map((row) => (
                                    <TableRow key={row.default.id}>
                                        <TableCell className="max-w-0">
                                            <span className="flex min-w-0 items-center gap-2">
                                                <span aria-hidden className="flex flex-none">
                                                    <Mark shift={row.default} />
                                                </span>
                                                <span className="truncate font-medium">{row.default.name}</span>
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground tabular-nums">
                                            {describe(row.default)}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {formatMinutes(row.default.required)}
                                        </TableCell>
                                        <TableCell>
                                            <StateBadge row={row} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <RefreshButton type="shift" row={row} manage={manage} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>

                    <Card className="min-w-min overflow-visible">
                        <CardHeader>
                            <CardTitle>Schedules</CardTitle>
                            <CardDescription className="ml-auto tabular-nums">
                                {schedules.length} {schedules.length === 1 ? 'default' : 'defaults'}
                            </CardDescription>
                        </CardHeader>

                        <Table style={{ minWidth: SCHEDULE_MIN_WIDTH }}>
                            <TableCaption className="sr-only mt-0">
                                Default schedules, and what this agency has of each
                            </TableCaption>
                            <TableHeader sticky>
                                <TableRow>
                                    <TableHead style={{ width: SCHEDULE_COLUMNS.schedule }}>Schedule</TableHead>
                                    <TableHead>Cycle</TableHead>
                                    <TableHead style={{ width: SCHEDULE_COLUMNS.days }}>Days</TableHead>
                                    <TableHead style={{ width: SCHEDULE_COLUMNS.state }}>Here</TableHead>
                                    <TableHead style={{ width: SCHEDULE_COLUMNS.actions }}>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {schedules.map((row) => (
                                    <TableRow key={row.default.id}>
                                        <TableCell className="max-w-0">
                                            <span className="block truncate font-medium">{row.default.name}</span>
                                        </TableCell>
                                        <TableCell>
                                            <Cycle schedule={row.default} />
                                        </TableCell>
                                        <TableCell className="tabular-nums">{row.default.length}</TableCell>
                                        <TableCell>
                                            <StateBadge row={row} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <RefreshButton type="schedule" row={row} manage={manage} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                </div>
            )}
        </AppLayout>
    );
}

/**
 * The page's one primary action. It says how many rows it will bring over, so
 * pressing it is not a guess — and it is absent altogether once nothing is
 * missing, rather than sitting there doing nothing.
 */
function CopyButton({ missing }: { missing: number }) {
    return (
        <Form {...copy.form()} options={{ preserveScroll: true }} disableWhileProcessing>
            {({ processing }) => (
                <Button type="submit" disabled={processing}>
                    <CopyPlusIcon aria-hidden strokeWidth={1.5} />
                    Copy {missing} {missing === 1 ? 'default' : 'defaults'}
                </Button>
            )}
        </Form>
    );
}

/**
 * Refresh, and only where it can succeed: a copy that points back at this
 * default and has drifted from it. A row that is up to date, unlinked or not
 * copied at all gets nothing — the state beside it already says why.
 */
function RefreshButton({
    type,
    row,
    manage,
}: {
    type: 'shift' | 'schedule';
    row: Row<{ id: string }>;
    manage: boolean;
}) {
    if (!manage || row.copy === null || !row.linked || !row.differs) {
        return null;
    }

    return (
        <Form {...refresh.form()} options={{ preserveScroll: true }} className="inline" disableWhileProcessing>
            {({ processing }) => (
                <>
                    <input type="hidden" name="type" value={type} />
                    <input type="hidden" name="id" value={row.copy?.id ?? ''} />
                    <Button type="submit" variant="outline" size="sm" disabled={processing}>
                        <RotateCcwIcon aria-hidden strokeWidth={1.5} />
                        Refresh
                    </Button>
                </>
            )}
        </Form>
    );
}

/** The row's state as a word on a tint — never the tint alone (§5.13). */
function StateBadge({ row }: { row: Row<unknown> }) {
    const { label, variant } = STATES[stateOf(row)];

    return (
        <Badge variant={variant} dot={variant !== 'outline'}>
            {label}
        </Badge>
    );
}

/**
 * A shift's mark: the ramp chip for a working day, the hatch for a rest day,
 * the dashed box for a remote one — the same three marks the roster grid
 * draws (§5.23), so a default is recognisable before its name is read.
 */
function Mark({ shift }: { shift: Shift }) {
    if (shift.kind === 'off') {
        return <OffBox className="h-[22px] w-[21px]" />;
    }

    if (shift.kind === 'remote') {
        return <RemoteBox className="h-[22px] w-[21px]" />;
    }

    return (
        <ShiftChip slot={shift.color as Slot} className="h-[22px] w-[21px]">
            <span aria-hidden>{shift.name.slice(0, 1).toUpperCase()}</span>
        </ShiftChip>
    );
}

/**
 * A schedule's cycle drawn as its turns, in order.
 *
 * The marks are decoration over a name list a screen reader gets in full, so
 * the whole strip is `aria-hidden` and the names sit beside it in an sr-only
 * span. A long rotation is counted rather than drawn past `CYCLE_SHOWN`: a
 * cycle may legally run to 366 days, and a row is not the place to read one.
 */
function Cycle({ schedule }: { schedule: Schedule }) {
    const turns = schedule.turns ?? [];
    const names = turns.map((turn) => turn.shift?.name).filter((name): name is string => name !== undefined);
    const shown = turns.slice(0, CYCLE_SHOWN);
    const rest = turns.length - shown.length;

    if (turns.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className="flex items-center gap-2">
            <span aria-hidden className="flex flex-wrap items-center gap-1">
                {shown.map((turn) => (
                    <span key={turn.id}>
                        {turn.shift ? <Mark shift={turn.shift} /> : <OffBox className="h-[22px] w-[21px]" />}
                    </span>
                ))}
            </span>
            {rest > 0 && <span className="text-muted-foreground tabular-nums">+{rest}</span>}
            <span className="sr-only">{names.join(', ')}</span>
        </span>
    );
}

/** Which of the four states a row is in. Resolved once, here, for both tables. */
function stateOf(row: Row<unknown>): State {
    if (row.copy === null) {
        return 'missing';
    }

    if (!row.linked) {
        return 'own';
    }

    return row.differs ? 'changed' : 'current';
}

/**
 * What a default day expects, as words rather than a slot dump: the in/out
 * pairs for a working shift, and a plain statement for the two shifts that
 * have no slots at all and are told apart by `remote`.
 */
function describe(shift: Shift): string {
    if (shift.kind === 'off') {
        return 'Nothing expected';
    }

    if (shift.kind === 'remote') {
        return 'Credited on attestation';
    }

    return shift.slots.map((slot) => `${slot.in}–${slot.out}`).join(' · ');
}

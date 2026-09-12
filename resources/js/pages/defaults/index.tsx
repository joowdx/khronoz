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

interface Row<T> {
    default: T;
    copy: T | null;
    linked: boolean;
    differs: boolean;
}

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

const SHIFT_FLEX_MIN = 220;

const SHIFT_MIN_WIDTH = Object.values(SHIFT_COLUMNS).reduce((sum, width) => sum + width, 0) + SHIFT_FLEX_MIN;

const SCHEDULE_COLUMNS = {
    schedule: 240,
    days: 90,
    state: 140,
    actions: 110,
} as const;

const SCHEDULE_FLEX_MIN = 260;

const SCHEDULE_MIN_WIDTH = Object.values(SCHEDULE_COLUMNS).reduce((sum, width) => sum + width, 0) + SCHEDULE_FLEX_MIN;

const CYCLE_SHOWN = 14;

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

function StateBadge({ row }: { row: Row<unknown> }) {
    const { label, variant } = STATES[stateOf(row)];

    return (
        <Badge variant={variant} dot={variant !== 'outline'}>
            {label}
        </Badge>
    );
}

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

function stateOf(row: Row<unknown>): State {
    if (row.copy === null) {
        return 'missing';
    }

    if (!row.linked) {
        return 'own';
    }

    return row.differs ? 'changed' : 'current';
}

function describe(shift: Shift): string {
    if (shift.kind === 'off') {
        return 'Nothing expected';
    }

    if (shift.kind === 'remote') {
        return 'Credited on attestation';
    }

    return shift.slots.map((slot) => `${slot.in}–${slot.out}`).join(' · ');
}

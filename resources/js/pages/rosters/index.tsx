import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon } from 'lucide-react';
import { AssignSheet } from '@/components/roster/assign-sheet';
import { RosterGrid, type RosterDay, type RosterGroup } from '@/components/roster/roster-grid';
import { Combobox } from '@/components/combobox';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/hooks/use-can';
import { index } from '@/routes/rosters';
import type { Schedule, SharedProps } from '@/types';

interface Filters {
    month: string;
    workgroup: string;
    schedule: string;
}

interface Unrostered {
    id: string;
    name: string;
    number: string;
    workgroup: string | null;
    until: string | null;
}

const PARTIAL = ['days', 'groups', 'unrostered', 'legend', 'note', 'filters'];

const COLUMNS = { number: 120, workgroup: 220, until: 160, actions: 68 } as const;
const FLEX_MIN = 240;
const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

/** Drop the defaults, so a cleared filter leaves the query string rather than sitting in it empty. */
function query(filters: Filters): Record<string, string> {
    const params: Record<string, string> = { month: filters.month };

    if (filters.workgroup !== '') {
        params.workgroup = filters.workgroup;
    }

    if (filters.schedule !== '') {
        params.schedule = filters.schedule;
    }

    return params;
}

/**
 * The roster grid, and the two things that have to sit beside it: who is not on
 * it, and the way onto it.
 *
 * The grid draws the roster **as planned**. A holiday or a suspension washes
 * its column and adds a note, but never rewrites a chip — what a day actually
 * became belongs to Workdays and the printed form, and a grid that answered
 * both questions would answer neither clearly.
 */
export default function Index({
    days,
    groups,
    unrostered,
    legend,
    note,
    filters,
    workgroups,
    schedules,
}: {
    days: RosterDay[];
    groups: RosterGroup[];
    unrostered: Unrostered[];
    legend: { name: string; slot: 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8; letter: string; hours: string; kind: string; night: boolean }[];
    note: string | null;
    filters: Filters;
    workgroups: { id: string; name: string }[];
    schedules: Schedule[];
}) {
    const can = useCan();
    const manage = can('scheduling.manage');
    const [selected, setSelected] = useState<string[]>([]);
    const [assigning, setAssigning] = useState(false);
    const { agency } = usePage<SharedProps>().props;

    const go = (next: Partial<Filters>) => {
        router.get(index.url(), query({ ...filters, ...next }), {
            only: PARTIAL,
            preserveState: true,
            preserveScroll: true,
        });
    };

    // The now-line is a whole column, not a time of day: the grid's unit is the
    // day, so it marks today or nothing.
    const nowIndex = days.findIndex((day) => day.today);

    const everyone = groups.flatMap((group) => group.rows.map((row) => row.id));
    const toggleAll = (checked: boolean) => setSelected(checked ? everyone : []);

    return (
        <AppLayout>
            <PageHeader
                title="Rosters"
                description="Who is on which schedule, and what each day expects of them."
                month={{ value: filters.month, onChange: (month) => go({ month }) }}
                actions={
                    manage ? (
                        <Button onClick={() => setAssigning(true)} disabled={selected.length === 0}>
                            <PlusIcon aria-hidden strokeWidth={1.5} />
                            Assign schedule
                        </Button>
                    ) : undefined
                }
            />

            <div className="mb-6 flex flex-wrap items-end gap-3">
                <Field label="Workgroup" className="w-[240px]">
                    {({ id }) => (
                        <Combobox
                            id={id}
                            value={filters.workgroup === '' ? null : filters.workgroup}
                            onValueChange={(value) => go({ workgroup: value ?? '' })}
                            placeholder="All workgroups"
                            searchPlaceholder="Search workgroups"
                            empty="No such workgroup."
                            clearLabel="All workgroups"
                            options={workgroups.map((workgroup) => ({ value: workgroup.id, label: workgroup.name }))}
                        />
                    )}
                </Field>
                <Field label="Schedule" className="w-[240px]">
                    {({ id }) => (
                        <Combobox
                            id={id}
                            value={filters.schedule === '' ? null : filters.schedule}
                            onValueChange={(value) => go({ schedule: value ?? '' })}
                            placeholder="All schedules"
                            searchPlaceholder="Search schedules"
                            empty="No such schedule."
                            clearLabel="All schedules"
                            options={schedules.map((schedule) => ({ value: schedule.id, label: schedule.name }))}
                        />
                    )}
                </Field>
                {(filters.workgroup !== '' || filters.schedule !== '') && (
                    <Button variant="ghost" onClick={() => go({ workgroup: '', schedule: '' })}>
                        Clear filters
                    </Button>
                )}
                {selected.length > 0 && (
                    <span className="text-muted-foreground ml-auto text-[13px] leading-5">
                        {selected.length} selected
                        <Button variant="ghost" className="ml-2 h-8" onClick={() => setSelected([])}>
                            Clear
                        </Button>
                    </span>
                )}
            </div>

            {groups.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        title="Nobody is rostered this month"
                        description="Assign a schedule to put someone on the grid. A roster runs from a date, with the cycle anchored where you say."
                    />
                </Card>
            ) : (
                <Card
                    // `w-full`, never `min-w-min`: the grid's frozen columns are sticky
                    // inside its own scroller, so that scroller has to be the thing that
                    // scrolls. Let the panel size to the grid's 1310px instead and
                    // #main-content scrolls, the sticky cells have no scrollport of their
                    // own and the Employee and Schedule columns slide away with everything
                    // else — the one thing the grid exists to prevent. No `overflow-hidden`
                    // here either: an overflow ancestor becomes the scrollport
                    // (components.md).
                    className="w-full p-0"
                >
                    <SelectionBar
                        manage={manage}
                        all={everyone.length > 0 && selected.length === everyone.length}
                        onToggle={toggleAll}
                        count={everyone.length}
                    />
                    <RosterGrid
                        days={days}
                        groups={groups}
                        nowIndex={nowIndex === -1 ? null : nowIndex}
                        legend={legend}
                        note={note}
                        selected={manage ? selected : undefined}
                        onToggle={
                            manage
                                ? (id) =>
                                      setSelected((current) =>
                                          current.includes(id)
                                              ? current.filter((other) => other !== id)
                                              : [...current, id],
                                      )
                                : undefined
                        }
                    />
                </Card>
            )}

            {unrostered.length > 0 && (
                <section className="mt-10">
                    <div className="border-border mb-4 flex items-baseline justify-between border-b pb-3">
                        <h2 className="text-sm leading-5 font-semibold tracking-[-0.002em]">Without a roster</h2>
                        <span className="text-muted-foreground text-[13px] leading-5 tabular-nums">
                            {unrostered.length} {unrostered.length === 1 ? 'employee' : 'employees'}
                        </span>
                    </div>
                    <Card className="min-w-min overflow-visible">
                        <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                            <TableCaption className="sr-only mt-0">
                                Employees no roster covers this month.
                            </TableCaption>
                            <TableHeader sticky>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead style={{ width: COLUMNS.number }}>Number</TableHead>
                                    <TableHead style={{ width: COLUMNS.workgroup }}>Workgroup</TableHead>
                                    <TableHead style={{ width: COLUMNS.until }}>Last rostered</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {unrostered.map((person) => (
                                    <TableRow key={person.id}>
                                        <TableCell className="max-w-0">
                                            <span className="truncate">{person.name}</span>
                                        </TableCell>
                                        <TableCell className="tabular-nums">{person.number}</TableCell>
                                        <TableCell>{person.workgroup ?? '—'}</TableCell>
                                        <TableCell className="tabular-nums">{person.until ?? 'Never'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                </section>
            )}

            <AssignSheet
                open={assigning}
                onOpenChange={setAssigning}
                employees={selected}
                schedules={schedules}
                month={filters.month}
                agency={agency?.name ?? null}
                onAssigned={() => setSelected([])}
            />
        </AppLayout>
    );
}

/**
 * Selection lives above the grid rather than as a column inside it: the
 * artboard draws no checkbox column, and adding one would cost 27px of every
 * row and a fourth frozen cell. Picking people is a mode you enter, not a
 * permanent part of reading the timetable.
 */
function SelectionBar({
    manage,
    all,
    onToggle,
    count,
}: {
    manage: boolean;
    all: boolean;
    onToggle: (checked: boolean) => void;
    count: number;
}) {
    if (!manage) {
        return null;
    }

    return (
        <div className="border-border flex items-center gap-2.5 border-b px-5 py-2.5">
            <Checkbox id="select-all" checked={all} onCheckedChange={(value) => onToggle(value === true)} />
            <label htmlFor="select-all" className="text-muted-foreground text-xs leading-4">
                Select all {count} rostered
            </label>
        </div>
    );
}

import { Link, router } from '@inertiajs/react';
import { MoonIcon, MoreHorizontalIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { Combobox } from '@/components/combobox';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDay } from '@/lib/dates';
import { create, destroy, edit, index } from '@/routes/overtimes';
import type { Choice, Employee, Overtime } from '@/types';

interface Filters {
    employee: string;
    mode: string;
    from: string;
    to: string;
}

interface Pagination {
    from: number | null;
    to: number | null;
    total: number;
    previous: string | null;
    next: string | null;
}

const PARTIAL = ['overtimes', 'pagination', 'filters'];

const COLUMNS = {
    date: 150,
    hours: 190,
    mode: 190,
    actions: 68,
} as const;

/** What the flexible Person/purpose column needs for a real authorisation line. */
const FLEX_MIN = 300;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

function query(filters: Filters): Record<string, string> {
    const params: Record<string, string> = {};

    for (const key of ['employee', 'mode', 'from', 'to'] as const) {
        if (filters[key] !== '') {
            params[key] = filters[key];
        }
    }

    return params;
}

/**
 * Authorised overtime, most recent first.
 *
 * The Hours column is the one that needed thought. An authorisation routinely
 * crosses midnight, and `22:00–02:00` rendered plainly reads as running
 * backwards — so an overnight stretch is marked, and says which day it ends
 * on. The Date column is `starts::date`, generated, so a stretch belongs to
 * the day it began on. That is how a DTR reads it.
 */
export default function Index({
    overtimes,
    pagination,
    filters,
    employees,
    modes,
}: {
    overtimes: Overtime[];
    pagination: Pagination;
    filters: Filters;
    employees: Employee[];
    modes: Choice[];
}) {
    const can = useCan();
    const manage = can('calendar.manage');

    function go(next: Partial<Filters>) {
        router.get(index.url(), query({ ...filters, ...next }), {
            only: PARTIAL,
            preserveState: true,
            preserveScroll: true,
        });
    }

    const authorise = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden strokeWidth={1.5} />
                Authorise overtime
            </Link>
        </Button>
    ) : null;

    const filtered = filters.employee !== '' || filters.mode !== '' || filters.from !== '' || filters.to !== '';

    return (
        <AppLayout>
            <PageHeader
                title="Overtime"
                description="Work beyond the shift, authorised in advance."
                actions={authorise}
            />

            <div className="mb-6 flex flex-wrap items-end gap-3">
                <Field className="w-[240px]" label="Person" htmlFor="employee">
                    {({ id }) => (
                        <Combobox
                            id={id}
                            value={filters.employee === '' ? null : filters.employee}
                            onValueChange={(value) => go({ employee: value ?? '' })}
                            placeholder="Everyone"
                            searchPlaceholder="Search employees"
                            empty="Nobody by that name."
                            clearLabel="Everyone"
                            options={employees.map((employee) => ({
                                value: employee.id,
                                label: employee.name,
                                keywords: [employee.number],
                                trigger: employee.name,
                            }))}
                        />
                    )}
                </Field>
                <Field className="w-[220px]" label="Compensated by" htmlFor="mode">
                    {({ id }) => (
                        <Combobox
                            id={id}
                            value={filters.mode === '' ? null : filters.mode}
                            onValueChange={(value) => go({ mode: value ?? '' })}
                            placeholder="Any"
                            searchPlaceholder="Search"
                            empty="No such mode."
                            clearLabel="Any"
                            options={modes.map((option) => ({ ...option, trigger: option.label }))}
                        />
                    )}
                </Field>
                <Field className="w-[160px]" label="From" htmlFor="from">
                    {({ id }) => (
                        <Input id={id} type="date" value={filters.from} onChange={(e) => go({ from: e.target.value })} />
                    )}
                </Field>
                <Field className="w-[160px]" label="To" htmlFor="to">
                    {({ id }) => (
                        <Input id={id} type="date" value={filters.to} onChange={(e) => go({ to: e.target.value })} />
                    )}
                </Field>
                {filtered && (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            router.get(index.url(), {}, { only: PARTIAL, preserveState: true, preserveScroll: true })
                        }
                    >
                        Clear filters
                    </Button>
                )}
            </div>

            {overtimes.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title={filtered ? 'Nothing matches those filters' : 'No overtime authorised'}
                        description={
                            filtered
                                ? 'Widen the dates, or clear the filters to see everything on record.'
                                : 'Authorise work beyond the shift here. Hours outside an authorisation are recorded but not credited.'
                        }
                        action={filtered ? undefined : (authorise ?? undefined)}
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>Authorised</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {pagination.from}–{pagination.to} of {pagination.total.toLocaleString()}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Authorised overtime, most recent first — a stretch belongs to the day it began on
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.date }}>Date</TableHead>
                                <TableHead>Person</TableHead>
                                <TableHead style={{ width: COLUMNS.hours }}>Hours</TableHead>
                                <TableHead style={{ width: COLUMNS.mode }}>Compensated by</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {overtimes.map((overtime) => (
                                <TableRow key={overtime.id}>
                                    <TableCell className="tabular-nums">{formatDay(overtime.date)}</TableCell>
                                    <TableCell className="max-w-0">
                                        <span className="flex min-w-0 flex-col">
                                            <span className="truncate">
                                                {overtime.employee?.name ?? (
                                                    <span className="text-muted-foreground">Unknown</span>
                                                )}
                                            </span>
                                            <span className="text-muted-foreground truncate text-xs">
                                                {overtime.purpose}
                                            </span>
                                        </span>
                                    </TableCell>
                                    {/*
                                      An overnight stretch is marked, because
                                      "22:00–02:00" on its own reads as running
                                      backwards. The moon carries the day it
                                      ends on for anyone who needs it.
                                    */}
                                    <TableCell className="tabular-nums">
                                        <span className="flex items-center gap-1.5">
                                            {clock(overtime.starts)}–{clock(overtime.ends)}
                                            {overtime.overnight && (
                                                <MoonIcon
                                                    aria-label={`Ends the next day, ${formatDay(overtime.ends.slice(0, 10))}`}
                                                    strokeWidth={1.5}
                                                    className="text-muted-foreground size-3.5 shrink-0"
                                                />
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell>{overtime.mode.label}</TableCell>
                                    <TableCell className="text-right">
                                        <RowMenu overtime={overtime} manage={manage} />
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

/** `HH:MM` out of a `Y-m-d H:i:s` timestamp, without parsing it into a Date. */
function clock(timestamp: string): string {
    return timestamp.slice(11, 16);
}

function RowMenu({ overtime, manage }: { overtime: Overtime; manage: boolean }) {
    if (!manage) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Actions for ${overtime.employee?.name ?? 'this authorisation'}`}
                >
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(overtime)} className="w-full">
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit authorisation
                    </Link>
                </DropdownMenuItem>
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                            <Trash2Icon aria-hidden strokeWidth={1.5} />
                            Withdraw authorisation
                        </DropdownMenuItem>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Withdraw this authorisation?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Those hours stop being credited as overtime. The punches themselves are untouched — they
                                stay on record either way.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep it</AlertDialogCancel>
                            <AlertDialogAction variant="destructive" onClick={() => router.delete(destroy.url(overtime))}>
                                Withdraw authorisation
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

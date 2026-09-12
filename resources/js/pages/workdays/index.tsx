import { Link, router } from '@inertiajs/react';
import { Combobox } from '@/components/combobox';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { MinuteCells, PunchChain, Who2, WorkdayStatus } from '@/components/workday-cells';
import AppLayout from '@/layouts/app-layout';
import { formatDayWithWeekday, manilaToday } from '@/lib/dates';
import { index } from '@/routes/workdays';
import { index as timelogsIndex } from '@/routes/timelogs';
import type { Choice, Employee, Workday } from '@/types';

interface Filters {
    month: string;
    employee: string;
    status: string;
    attention: boolean;
}

interface Pagination {
    from: number | null;
    to: number | null;
    total: number;
    previous: string | null;
    next: string | null;
}

const PARTIAL = ['workdays', 'pagination', 'filters'];

const COLUMNS = {
    date: 190,
    shift: 140,
    status: 200,
    chain: 240,
    worked: 80,
    tardy: 80,
    undertime: 100,
    excess: 80,
    night: 80,
} as const;

const FLEX_MIN = 240;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

function query(filters: Filters): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.month !== manilaToday().slice(0, 7)) {
        params.month = filters.month;
    }

    if (filters.employee !== '') {
        params.employee = filters.employee;
    }

    if (filters.status !== '') {
        params.status = filters.status;
    }

    if (filters.attention) {
        params.attention = '1';
    }

    return params;
}

export default function Index({
    workdays,
    pagination,
    filters,
    employees,
    statuses,
}: {
    workdays: Workday[];
    pagination: Pagination;
    filters: Filters;
    employees: Employee[];
    statuses: Choice[];
}) {
    function go(next: Partial<Filters>) {
        router.get(index.url(), query({ ...filters, ...next }), {
            only: PARTIAL,
            preserveState: true,
            preserveScroll: true,
        });
    }

    const filtered = filters.employee !== '' || filters.status !== '' || filters.attention;

    const empty = (
        <EmptyState
            className="py-0"
            title={filtered ? 'Nothing matches those filters' : 'No workdays this month'}
            description={
                filtered
                    ? 'Clear the employee, status or attention filter to see every computed day this month.'
                    : 'A workday is computed from imported punches — it is not typed in. Import a device’s attlog from Timelogs and the engine will fill this list.'
            }
            action={
                filtered ? (
                    <Button variant="outline" onClick={() => go({ employee: '', status: '', attention: false })}>
                        Clear filters
                    </Button>
                ) : (
                    <Button variant="outline" asChild>
                        <Link href={timelogsIndex()}>Go to Timelogs</Link>
                    </Button>
                )
            }
        />
    );

    return (
        <AppLayout>
            <PageHeader
                title="Workdays"
                description="What the engine computed this month, and the punches that still need a side."
                month={{ value: filters.month, onChange: (month) => go({ month }) }}
            />

            <div className="mb-6 flex flex-wrap items-end gap-3">
                <Field className="w-[240px]" label="Employee" htmlFor="employee">
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

                <Field className="w-[200px]" label="Status" htmlFor="status">
                    {({ id }) => (
                        <Select
                            value={filters.status === '' ? 'any' : filters.status}
                            onValueChange={(value) => go({ status: value === 'any' ? '' : value })}
                        >
                            <SelectTrigger id={id} aria-label="Filter by status" className="w-full justify-start gap-2">
                                <span className="text-muted-foreground font-normal">Status</span>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent position="popper" align="start" sideOffset={6}>
                                <SelectItem value="any">Any</SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem key={status.value} value={status.value}>
                                        {status.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </Field>

                <Field label="Only show" htmlFor="attention">
                    {({ id }) => (
                        <ToggleGroup
                            id={id}
                            type="multiple"
                            value={filters.attention ? ['attention'] : []}
                            onValueChange={(value) => go({ attention: value.includes('attention') })}
                            variant="outline"
                        >
                            <ToggleGroupItem value="attention">Needs attention</ToggleGroupItem>
                        </ToggleGroup>
                    )}
                </Field>

                {filtered && (
                    <Button variant="ghost" onClick={() => go({ employee: '', status: '', attention: false })}>
                        Clear filters
                    </Button>
                )}
            </div>

            {workdays.length === 0 && !filtered ? (
                <Card className="p-8">{empty}</Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>This month</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {pagination.total === 0
                                ? 'None'
                                : `${pagination.from}–${pagination.to} of ${pagination.total.toLocaleString()}`}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Workdays this month, by date then person. A dash in the chain is a punch still due.
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead>Person</TableHead>
                                <TableHead style={{ width: COLUMNS.date }}>Date</TableHead>
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
                            {workdays.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={10} className="h-auto whitespace-normal">
                                        <div className="px-2 py-6">{empty}</div>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                workdays.map((workday) => (
                                    <TableRow key={workday.id}>
                                        <TableCell className="h-[52px] max-w-0">
                                            <Who2 employee={workday.employee} />
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {formatDayWithWeekday(workday.date)}
                                        </TableCell>
                                        <TableCell>
                                            {workday.shift_name ?? <span className="text-muted-foreground">—</span>}
                                        </TableCell>
                                        <TableCell>
                                            <WorkdayStatus status={workday.status} premium={workday.premium} />
                                        </TableCell>
                                        <TableCell>
                                            <PunchChain punches={workday.punches} date={workday.date} />
                                        </TableCell>
                                        <MinuteCells workday={workday} />
                                    </TableRow>
                                ))
                            )}
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

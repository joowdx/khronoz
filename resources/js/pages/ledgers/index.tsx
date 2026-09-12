import { Form, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ChoiceField } from '@/components/choice-field';
import { Combobox } from '@/components/combobox';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { FormErrors } from '@/components/form-errors';
import { PageHeader } from '@/components/page-header';
import { LedgerStatus } from '@/components/rendition-status';
import { Who2 } from '@/components/workday-cells';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatShortDay, manilaToday } from '@/lib/dates';
import { formatMinutes } from '@/lib/minutes';
import { index, lock, show } from '@/routes/ledgers';
import { download as currentDownload } from '@/routes/employees/ledger';
import type { Cadence, Choice, Employee, Ledger, SharedProps } from '@/types';
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
const PARTIAL = ['ledgers', 'pagination', 'filters'];
const COLUMNS = { range: 220, days: 70, worked: 90, tardy: 90, undertime: 100, status: 230, actions: 90 };
const TABLE_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 240);
function query(filters: Filters): Record<string, string> {
    return filters.month === manilaToday().slice(0, 7) ? {} : { month: filters.month };
}
function previousMonth(): { starts: string; ends: string } {
    const [year, month] = manilaToday().split('-').map(Number);
    return {
        starts: new Date(Date.UTC(year!, month! - 2, 1)).toISOString().slice(0, 10),
        ends: new Date(Date.UTC(year!, month! - 1, 0)).toISOString().slice(0, 10),
    };
}
export default function Index({
    ledgers,
    pagination,
    filters,
    employees,
    cadences,
    works,
}: {
    ledgers: LedgerRow[];
    pagination: Pagination;
    filters: Filters;
    employees: Employee[];
    cadences: Cadence[];
    works: Choice[];
}) {
    const can = useCan();
    const { auth } = usePage<SharedProps>().props;
    const [mode, setMode] = useState<'lock' | 'download' | null>(null);
    const lockEmployees = can('ledgers.manage')
        ? employees
        : employees.filter((employee) => employee.id === auth?.user?.employee_id);
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
                description="Locked date ranges, their attestations, and retained revisions."
                month={{ value: filters.month, onChange: (month) => go({ month }) }}
                actions={lockEmployees.length ? <Button onClick={() => setMode('lock')}>Lock range</Button> : undefined}
            />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <p className="text-muted-foreground max-w-2xl text-sm">
                    A ledger is recorded when its range is locked. Current attendance can be downloaded before locking.
                </p>
                <Button variant="outline" onClick={() => setMode('download')} disabled={employees.length === 0}>
                    Current PDF
                </Button>
            </div>
            {ledgers.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No locked ranges this month"
                        description="Lock a completed period to freeze its attendance and begin certification."
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>Recorded ranges</CardTitle>
                        <CardDescription className="ml-auto">
                            {pagination.from}–{pagination.to} of {pagination.total.toLocaleString()}
                        </CardDescription>
                    </CardHeader>
                    <Table style={{ minWidth: TABLE_WIDTH }}>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead>Person</TableHead>
                                <TableHead style={{ width: COLUMNS.range }}>Range and scope</TableHead>
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
                            {ledgers.map((ledger) => (
                                <TableRow key={ledger.id}>
                                    <TableCell className="max-w-0">
                                        <Link href={show(ledger)} className="hover:text-acc-text block">
                                            <Who2 employee={ledger.employee} />
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <p>
                                            {formatShortDay(ledger.starts)} – {formatShortDay(ledger.ends)}
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {ledger.scope.label} · Revision {ledger.revision}
                                        </p>
                                    </TableCell>
                                    <TableCell numeric>{ledger.workdays_count}</TableCell>
                                    <TableCell numeric>{formatMinutes(ledger.worked)}</TableCell>
                                    <TableCell numeric>{formatMinutes(ledger.tardy)}</TableCell>
                                    <TableCell numeric>{formatMinutes(ledger.undertime)}</TableCell>
                                    <TableCell>
                                        <LedgerStatus ledger={ledger} />
                                    </TableCell>
                                    <TableCell>
                                        <Button asChild variant="ghost" size="sm">
                                            <Link href={show(ledger)}>Open</Link>
                                        </Button>
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
                        <Button asChild variant="outline">
                            <Link href={pagination.previous} only={PARTIAL} preserveScroll>
                                Previous
                            </Link>
                        </Button>
                    )}
                    {pagination.next && (
                        <Button asChild variant="outline">
                            <Link href={pagination.next} only={PARTIAL} preserveScroll>
                                Next
                            </Link>
                        </Button>
                    )}
                </div>
            )}
            <Sheet
                open={mode !== null}
                onOpenChange={(open) => {
                    if (!open) setMode(null);
                }}
            >
                <SheetContent className="overflow-y-auto sm:max-w-[520px]">
                    <SheetHeader className="flex-col items-start pr-12">
                        <SheetTitle>
                            {mode === 'lock' ? 'Lock a completed range' : 'Download current attendance'}
                        </SheetTitle>
                        <SheetDescription>
                            {mode === 'lock'
                                ? 'Use the exact start and end of a completed cadence period. The figures, identity, and certification policy are frozen together.'
                                : 'Choose up to 31 days. This download reflects current attendance and does not create a ledger.'}
                        </SheetDescription>
                    </SheetHeader>
                    {mode && (
                        <RangeForm
                            key={mode}
                            mode={mode}
                            employees={mode === 'lock' ? lockEmployees : employees}
                            cadences={cadences}
                            works={works}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </AppLayout>
    );
}
function RangeForm({
    mode,
    employees,
    cadences,
    works,
}: {
    mode: 'lock' | 'download';
    employees: Employee[];
    cadences: Cadence[];
    works: Choice[];
}) {
    const [employee, setEmployee] = useState<string | null>(employees.length === 1 ? employees[0]!.id : null);
    const [cadence, setCadence] = useState('');
    const [work, setWork] = useState('all');
    const range = previousMonth();
    function fields(errors: Record<string, string>) {
        return (
            <>
                <FormErrors
                    errors={Object.fromEntries(
                        Object.entries(errors).filter(
                            ([key]) => !['employee_id', 'starts', 'ends', 'cadence_id', 'scope', 'work'].includes(key),
                        ),
                    )}
                />
                <Field label="Employee" error={errors.employee_id}>
                    {({ id, invalid, describedBy }) => (
                        <Combobox
                            id={id}
                            name={mode === 'lock' ? 'employee_id' : undefined}
                            value={employee}
                            onValueChange={setEmployee}
                            options={employees.map((person) => ({
                                value: person.id,
                                label: person.name,
                                keywords: [person.number],
                            }))}
                            placeholder="Choose an employee"
                            invalid={invalid}
                            describedBy={describedBy}
                        />
                    )}
                </Field>
                {mode === 'lock' && (
                    <ChoiceField
                        label="Cadence"
                        name="cadence_id"
                        value={cadence}
                        onChange={setCadence}
                        choices={cadences.map((item) => ({
                            value: item.id,
                            label: item.retired_at ? `${item.name} (retired)` : item.name,
                        }))}
                        emptyLabel="Employee assignment or agency default"
                        error={errors.cadence_id}
                        hint="With no assignment or agency default, use a full calendar month."
                    />
                )}
                <div className="grid grid-cols-2 gap-3">
                    <Field label="First day" error={errors.starts}>
                        {({ id, invalid, describedBy }) => (
                            <Input
                                id={id}
                                name="starts"
                                type="date"
                                defaultValue={range.starts}
                                max={mode === 'lock' ? manilaToday() : undefined}
                                required
                                aria-invalid={invalid}
                                aria-describedby={describedBy}
                            />
                        )}
                    </Field>
                    <Field label="Last day" error={errors.ends}>
                        {({ id, invalid, describedBy }) => (
                            <Input
                                id={id}
                                name="ends"
                                type="date"
                                defaultValue={range.ends}
                                max={mode === 'lock' ? manilaToday() : undefined}
                                required
                                aria-invalid={invalid}
                                aria-describedby={describedBy}
                            />
                        )}
                    </Field>
                </div>
                <ChoiceField
                    label="Work scope"
                    name={mode === 'lock' ? 'scope' : 'work'}
                    value={work}
                    onChange={setWork}
                    choices={works}
                    error={errors.scope ?? errors.work}
                />
            </>
        );
    }
    return mode === 'lock' ? (
        <Form {...lock.form()} className="grid gap-6 px-4 pb-6" disableWhileProcessing>
            {({ errors, processing }) => (
                <>
                    {fields(errors)}
                    <Button type="submit" disabled={processing || !employee}>
                        Lock range
                    </Button>
                </>
            )}
        </Form>
    ) : (
        <form
            action={employee ? currentDownload.url(employee) : undefined}
            method="get"
            className="grid gap-6 px-4 pb-6"
        >
            {fields({})}
            <Button type="submit" disabled={!employee}>
                Download PDF
            </Button>
        </form>
    );
}

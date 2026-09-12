import { Link, router } from '@inertiajs/react';
import { MoreHorizontalIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
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
import { create, destroy, edit, index } from '@/routes/exemptions';
import type { Choice, Employee, Exemption } from '@/types';

interface Filters {
    employee: string;
    type: string;
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

const PARTIAL = ['exemptions', 'pagination', 'filters'];

const COLUMNS = {
    days: 230,
    kind: 190,
    hours: 140,
    actions: 68,
} as const;

const FLEX_MIN = 260;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

function query(filters: Filters): Record<string, string> {
    const params: Record<string, string> = {};

    for (const key of ['employee', 'type', 'from', 'to'] as const) {
        if (filters[key] !== '') {
            params[key] = filters[key];
        }
    }

    return params;
}

export default function Index({
    exemptions,
    pagination,
    filters,
    employees,
    types,
}: {
    exemptions: Exemption[];
    pagination: Pagination;
    filters: Filters;
    employees: Employee[];
    types: Choice[];
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

    const record = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden strokeWidth={1.5} />
                Record exemption
            </Link>
        </Button>
    ) : null;

    const filtered = filters.employee !== '' || filters.type !== '' || filters.from !== '' || filters.to !== '';

    return (
        <AppLayout>
            <PageHeader
                title="Exemptions"
                description="Leave, official business, travel, pass slips — the reasons a day is excused."
                actions={record}
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
                <Field className="w-[200px]" label="Kind" htmlFor="type">
                    {({ id }) => (
                        <Combobox
                            id={id}
                            value={filters.type === '' ? null : filters.type}
                            onValueChange={(value) => go({ type: value ?? '' })}
                            placeholder="Any kind"
                            searchPlaceholder="Search"
                            empty="No such kind."
                            clearLabel="Any kind"
                            options={types.map((option) => ({ ...option, trigger: option.label }))}
                        />
                    )}
                </Field>
                <Field className="w-[160px]" label="Overlapping from" htmlFor="from">
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

            {exemptions.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title={filtered ? 'Nothing matches those filters' : 'No exemptions yet'}
                        description={
                            filtered
                                ? 'Widen the dates, or clear the filters to see everything on record.'
                                : 'Record leave, official business, travel and pass slips here. One row covers the whole run of days it was granted for.'
                        }
                        action={filtered ? undefined : (record ?? undefined)}
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>On record</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {pagination.from}–{pagination.to} of {pagination.total.toLocaleString()}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">Exemptions, most recent first</TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.days }}>Days</TableHead>
                                <TableHead>Person</TableHead>
                                <TableHead style={{ width: COLUMNS.kind }}>Kind</TableHead>
                                <TableHead style={{ width: COLUMNS.hours }}>Hours</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {exemptions.map((exemption) => (
                                <TableRow key={exemption.id}>
                                    <TableCell className="tabular-nums">
                                        {exemption.spans_days ? (
                                            <span className="flex flex-col">
                                                <span>
                                                    {formatDay(exemption.date)} – {formatDay(exemption.until)}
                                                </span>
                                                <span className="text-muted-foreground text-xs">
                                                    {days(exemption)} days
                                                </span>
                                            </span>
                                        ) : (
                                            formatDay(exemption.date)
                                        )}
                                    </TableCell>
                                    <TableCell className="max-w-0">
                                        <span className="flex min-w-0 flex-col">
                                            <span className="truncate">
                                                {exemption.employee?.name ?? (
                                                    <span className="text-muted-foreground">Unknown</span>
                                                )}
                                            </span>
                                            {exemption.reference && (
                                                <span className="text-muted-foreground truncate text-xs">
                                                    {exemption.reference}
                                                </span>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell>{exemption.type.label}</TableCell>
                                    <TableCell className="tabular-nums">
                                        {exemption.starts && exemption.ends ? (
                                            `${exemption.starts.slice(0, 5)}–${exemption.ends.slice(0, 5)}`
                                        ) : (
                                            <span className="text-muted-foreground">Whole day</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowMenu exemption={exemption} manage={manage} />
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

function days(exemption: Exemption): number {
    const [fy, fm, fd] = exemption.date.split('-').map(Number);
    const [ty, tm, td] = exemption.until.split('-').map(Number);
    const from = Date.UTC(fy ?? 0, (fm ?? 1) - 1, fd ?? 1);
    const to = Date.UTC(ty ?? 0, (tm ?? 1) - 1, td ?? 1);

    return Math.round((to - from) / 86_400_000) + 1;
}

function RowMenu({ exemption, manage }: { exemption: Exemption; manage: boolean }) {
    if (!manage) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Actions for ${exemption.employee?.name ?? 'this exemption'}`}
                >
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(exemption)} className="w-full">
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit exemption
                    </Link>
                </DropdownMenuItem>
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                            <Trash2Icon aria-hidden strokeWidth={1.5} />
                            Remove exemption
                        </DropdownMenuItem>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Remove this exemption?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Those days stop being excused. Days already computed are not revisited.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep it</AlertDialogCancel>
                            <AlertDialogAction
                                variant="destructive"
                                onClick={() => router.delete(destroy.url(exemption))}
                            >
                                Remove exemption
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

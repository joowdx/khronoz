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
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDay } from '@/lib/dates';
import { create, destroy, edit, index } from '@/routes/holidays';
import type { Holiday } from '@/types';

const COLUMNS = {
    date: 190,
    rate: 190,
    scope: 130,
    actions: 68,
} as const;

const FLEX_MIN = 300;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

export default function Index({
    holidays,
    filters,
    years,
}: {
    holidays: Holiday[];
    filters: { year: string };
    years: string[];
}) {
    const can = useCan();
    const manage = can('calendar.manage');

    const addHoliday = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden strokeWidth={1.5} />
                Add holiday
            </Link>
        </Button>
    ) : null;

    const options = [...new Set([filters.year, ...years])].sort().reverse();

    return (
        <AppLayout>
            <PageHeader
                title="Holidays"
                description="Days on which no work is expected, or on which work is paid at a premium. National ones are listed for you."
                actions={addHoliday}
            />

            <div className="mb-6 flex flex-wrap items-end gap-3">
                <Field className="w-[160px]" label="Year" htmlFor="year">
                    {({ id }) => (
                        <Combobox
                            id={id}
                            value={filters.year}
                            onValueChange={(value) =>
                                router.get(index.url(), value ? { year: value } : {}, {
                                    only: ['holidays', 'filters'],
                                    preserveState: true,
                                    preserveScroll: true,
                                })
                            }
                            placeholder={filters.year}
                            searchPlaceholder="Search years"
                            empty="No holidays in that year."
                            options={options.map((year) => ({ value: year, label: year, trigger: year }))}
                        />
                    )}
                </Field>
            </div>

            {holidays.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title={`Nothing in ${filters.year}`}
                        description="National holidays are declared for every agency and appear here once they are proclaimed. Add your own for a city charter day or a local ordinance."
                        action={addHoliday ?? undefined}
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>{filters.year}</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {holidays.length} {holidays.length === 1 ? 'holiday' : 'holidays'}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Holidays in {filters.year}, in date order — a date may carry more than one
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.date }}>Date</TableHead>
                                <TableHead>Holiday</TableHead>
                                <TableHead style={{ width: COLUMNS.rate }}>Rate</TableHead>
                                <TableHead style={{ width: COLUMNS.scope }}>Declared by</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {holidays.map((holiday, position) => {
                                const sameDay = position > 0 && holidays[position - 1]?.date === holiday.date;

                                return (
                                    <TableRow key={holiday.id}>
                                        <TableCell className="tabular-nums">
                                            {sameDay ? (
                                                <span className="sr-only">{formatDay(holiday.date)}</span>
                                            ) : (
                                                formatDay(holiday.date)
                                            )}
                                        </TableCell>
                                        <TableCell className="max-w-0">
                                            <span className="flex min-w-0 flex-col">
                                                <span className="truncate">{holiday.name}</span>
                                                {holiday.reference && (
                                                    <span className="text-muted-foreground truncate text-xs">
                                                        {holiday.reference}
                                                    </span>
                                                )}
                                            </span>
                                        </TableCell>
                                        <TableCell>{holiday.type.label}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {holiday.national ? 'Nationwide' : 'This agency'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <RowMenu holiday={holiday} manage={manage} />
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </Card>
            )}
        </AppLayout>
    );
}

function RowMenu({ holiday, manage }: { holiday: Holiday; manage: boolean }) {
    if (!manage || holiday.national) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${holiday.name}`}>
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(holiday)} className="w-full">
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit holiday
                    </Link>
                </DropdownMenuItem>
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                            <Trash2Icon aria-hidden strokeWidth={1.5} />
                            Remove holiday
                        </DropdownMenuItem>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Remove {holiday.name}?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Days already computed are not revisited, so removing it changes what happens from here
                                rather than rewriting the past.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep holiday</AlertDialogCancel>
                            <AlertDialogAction
                                variant="destructive"
                                onClick={() => router.delete(destroy.url(holiday))}
                            >
                                Remove holiday
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

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

/** What the flexible Name column needs for a real proclamation title. */
const FLEX_MIN = 300;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

const RATES: Record<string, string> = {
    regular: 'Regular holiday',
    special: 'Special non-working',
    working: 'Special working',
    local: 'Local holiday',
};

/**
 * A year at a time, because that is the unit a proclamation arrives in.
 *
 * **Two holidays may share a date and both are owed** (dole-rules.md I.6), so
 * the list never groups by date or shows one row per day — a second holiday on
 * 30 November is a second row, sitting directly under the first, and the
 * higher rate is a fact about both rather than a merge.
 */
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

    // The years the agency actually has rows in, plus the one being viewed —
    // so the picker can always show its own selection even when a hand-typed
    // year has nothing in it.
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
                                // A second holiday on the same date is a second
                                // row, and the date is not repeated — so the
                                // pair reads as one day carrying two, which is
                                // exactly what it is.
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
                                        <TableCell>{RATES[holiday.type] ?? holiday.type}</TableCell>
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

/**
 * A national holiday has no menu at all.
 *
 * It belongs to the platform agency, and HolidayPolicy refuses an agency user
 * changing it — so offering the item and translating the 403 would be worse
 * than not offering it. The "Declared by" cell beside the gap says why.
 */
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

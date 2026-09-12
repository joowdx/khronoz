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
import { create, destroy, edit, index } from '@/routes/suspensions';
import type { Suspension } from '@/types';

const COLUMNS = {
    date: 180,
    covers: 240,
    hours: 150,
    actions: 68,
} as const;

const FLEX_MIN = 300;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

export default function Index({
    suspensions,
    filters,
    years,
}: {
    suspensions: Suspension[];
    filters: { year: string };
    years: string[];
}) {
    const can = useCan();
    const manage = can('calendar.manage');

    const declare = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden strokeWidth={1.5} />
                Declare suspension
            </Link>
        </Button>
    ) : null;

    const options = [...new Set([filters.year, ...years])].sort().reverse();

    return (
        <AppLayout>
            <PageHeader
                title="Suspensions"
                description="Days work was called off — a typhoon, a brownout, a transport strike."
                actions={declare}
            />

            <div className="mb-6 flex flex-wrap items-end gap-3">
                <Field className="w-[160px]" label="Year" htmlFor="year">
                    {({ id }) => (
                        <Combobox
                            id={id}
                            value={filters.year}
                            onValueChange={(value) =>
                                router.get(index.url(), value ? { year: value } : {}, {
                                    only: ['suspensions', 'filters'],
                                    preserveState: true,
                                    preserveScroll: true,
                                })
                            }
                            placeholder={filters.year}
                            searchPlaceholder="Search years"
                            empty="Nothing in that year."
                            options={options.map((year) => ({ value: year, label: year, trigger: year }))}
                        />
                    )}
                </Field>
            </div>

            {suspensions.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title={`Nothing in ${filters.year}`}
                        description="When work is called off — a typhoon signal, a brownout, a transport strike — declare it here and the day stops counting against everyone it covered."
                        action={declare ?? undefined}
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>{filters.year}</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {suspensions.length} {suspensions.length === 1 ? 'suspension' : 'suspensions'}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Suspensions in {filters.year}, newest first — a date may carry more than one
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.date }}>Date</TableHead>
                                <TableHead>Reason</TableHead>
                                <TableHead style={{ width: COLUMNS.covers }}>Covered</TableHead>
                                <TableHead style={{ width: COLUMNS.hours }}>Hours</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {suspensions.map((suspension, position) => {
                                const sameDay =
                                    position > 0 && suspensions[position - 1]?.date === suspension.date;

                                return (
                                <TableRow key={suspension.id}>
                                    <TableCell className="tabular-nums">
                                        {sameDay ? (
                                            <span className="sr-only">{formatDay(suspension.date)}</span>
                                        ) : (
                                            formatDay(suspension.date)
                                        )}
                                    </TableCell>
                                    <TableCell className="max-w-0">
                                        <span className="flex min-w-0 flex-col">
                                            <span className="truncate">{suspension.reason}</span>
                                            {suspension.reference && (
                                                <span className="text-muted-foreground truncate text-xs">
                                                    {suspension.reference}
                                                </span>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="max-w-0">
                                        <span className="block truncate">
                                            {suspension.workgroup?.name ?? 'The whole agency'}
                                        </span>
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {suspension.starts && suspension.ends ? (
                                            `${suspension.starts.slice(0, 5)}–${suspension.ends.slice(0, 5)}`
                                        ) : (
                                            <span className="text-muted-foreground">Whole day</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowMenu suspension={suspension} manage={manage} />
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

function RowMenu({ suspension, manage }: { suspension: Suspension; manage: boolean }) {
    if (!manage) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${suspension.reason}`}>
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(suspension)} className="w-full">
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit suspension
                    </Link>
                </DropdownMenuItem>
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                            <Trash2Icon aria-hidden strokeWidth={1.5} />
                            Withdraw suspension
                        </DropdownMenuItem>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Withdraw this suspension?</AlertDialogTitle>
                            <AlertDialogDescription>
                                {formatDay(suspension.date)} stops being excused for everyone it covered. Days already
                                computed are not revisited.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep it</AlertDialogCancel>
                            <AlertDialogAction
                                variant="destructive"
                                onClick={() => router.delete(destroy.url(suspension))}
                            >
                                Withdraw suspension
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

import { Link, router } from '@inertiajs/react';
import { MoreHorizontalIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { OffBox, RemoteBox, ShiftChip } from '@/components/shift-chip';
import { EmptyState } from '@/components/empty-state';
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
import { create, destroy, edit, index } from '@/routes/shifts';
import type { Shift } from '@/types';
import type { Slot } from '@/components/shift-chip';

const COLUMNS = {
    chip: 64,
    kind: 130,
    required: 110,
    flex: 90,
    actions: 68,
} as const;

const FLEX_MIN = 200;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

export default function Index({ shifts, usedColors }: { shifts: Shift[]; usedColors: number[] }) {
    const can = useCan();
    const manage = can('scheduling.manage');

    const addShift = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden strokeWidth={1.5} />
                Add shift
            </Link>
        </Button>
    ) : null;

    return (
        <AppLayout>
            <PageHeader
                title="Shifts"
                description="Day templates the scheduler assigns to turns in a roster cycle."
                actions={addShift}
            />

            {shifts.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No shifts yet"
                        description="Add a working shift, an off day or a remote day to start building schedules."
                        action={addShift ?? undefined}
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>Shifts</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {shifts.length} {shifts.length === 1 ? 'shift' : 'shifts'}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">Shifts, in name order</TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.chip }}>
                                    <span className="sr-only">Colour</span>
                                </TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead style={{ width: COLUMNS.kind }}>Kind</TableHead>
                                <TableHead style={{ width: COLUMNS.required }}>Required</TableHead>
                                <TableHead style={{ width: COLUMNS.flex }}>Flex</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shifts.map((shift) => (
                                <TableRow key={shift.id}>
                                    <TableCell>
                                        {shift.kind === 'working' ? (
                                            <ShiftChip slot={shift.color as Slot} className="size-7">
                                                {shift.name.slice(0, 2).toUpperCase()}
                                            </ShiftChip>
                                        ) : shift.kind === 'remote' ? (
                                            <RemoteBox className="size-7" />
                                        ) : (
                                            <OffBox className="size-7" />
                                        )}
                                    </TableCell>
                                    <TableCell className="max-w-0">
                                        <span className="truncate">{shift.name}</span>
                                    </TableCell>
                                    <TableCell>
                                        {shift.kind === 'working'
                                            ? 'Working'
                                            : shift.kind === 'remote'
                                              ? 'Remote'
                                              : 'Off'}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {shift.kind === 'working' ? formatMinutes(shift.required) : '—'}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {shift.kind === 'working' && shift.flex > 0
                                            ? formatMinutes(shift.flex)
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowMenu shift={shift} manage={manage} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}
        </AppLayout>
    );
}

function formatMinutes(minutes: number): string {
    if (minutes === 0) {
        return '—';
    }

    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return `${h}:${String(m).padStart(2, '0')}`;
}

function RowMenu({ shift, manage }: { shift: Shift; manage: boolean }) {
    if (!manage) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${shift.name}`}>
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(shift)} className="w-full">
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit shift
                    </Link>
                </DropdownMenuItem>
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                            <Trash2Icon aria-hidden strokeWidth={1.5} />
                            Remove shift
                        </DropdownMenuItem>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Remove {shift.name}?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Any schedule turns still using this shift must be reassigned first. The database will
                                refuse the removal if any remain.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep shift</AlertDialogCancel>
                            <AlertDialogAction
                                variant="destructive"
                                onClick={() => router.delete(destroy.url(shift))}
                            >
                                Remove shift
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

import { Link, router } from '@inertiajs/react';
import { MoreHorizontalIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { ShiftGlyph, TurnStrip } from '@/components/schedule-fields';
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
import { create, destroy, edit } from '@/routes/schedules';
import type { Schedule } from '@/types';

const COLUMNS = {
    schedule: 260,
    cycle: 110,
    fallback: 180,
    actions: 68,
} as const;

/** What the flexible Cycle column needs for a fortnight of 18px marks. */
const FLEX_MIN = 340;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

/**
 * Every cycle the agency has, drawn.
 *
 * The strip is why this list is a table rather than a set of names: a schedule
 * is only legible as its days in order, and the marks are the same ones the
 * roster grid paints, so what is read here is what will be worked. Long cycles
 * are cut off with a count — the whole of a 21-day rotation is on its own edit
 * screen, and this is the shape of it at a glance.
 *
 * No pagination and no filters: an agency has schedules in single figures.
 */
export default function Index({ schedules }: { schedules: Schedule[] }) {
    const can = useCan();
    const manage = can('scheduling.manage');

    const addSchedule = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden strokeWidth={1.5} />
                Add schedule
            </Link>
        </Button>
    ) : null;

    return (
        <AppLayout>
            <PageHeader
                title="Schedules"
                description="A repeating cycle of shifts. Teams and rosters follow one."
                actions={addSchedule}
            />

            {schedules.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No schedules yet"
                        description="A schedule is a cycle of shifts — five days and two off, or a three-week hospital rotation. Build one here, then a team or a roster follows it."
                        action={addSchedule ?? undefined}
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>Cycles</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {schedules.length.toLocaleString()}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Every schedule of this agency, with its cycle in position order
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.schedule }}>Schedule</TableHead>
                                <TableHead style={{ width: COLUMNS.cycle }}>Length</TableHead>
                                <TableHead>Cycle</TableHead>
                                <TableHead style={{ width: COLUMNS.fallback }}>Falls back to</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {schedules.map((schedule) => (
                                <TableRow key={schedule.id}>
                                    <TableCell>
                                        <span className="flex min-w-0 flex-col">
                                            <span className="truncate">{schedule.name}</span>
                                            {schedule.origin_id && (
                                                <span className="text-muted-foreground truncate text-xs">
                                                    Copied from a default
                                                </span>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {schedule.length} {schedule.length === 1 ? 'day' : 'days'}
                                    </TableCell>
                                    <TableCell className="max-w-0">
                                        <TurnStrip turns={schedule.turns ?? []} />
                                    </TableCell>
                                    <TableCell>
                                        {schedule.fallback_shift ? (
                                            <span className="flex min-w-0 items-center gap-2">
                                                <ShiftGlyph shift={schedule.fallback_shift} />
                                                <span className="truncate">{schedule.fallback_shift.name}</span>
                                            </span>
                                        ) : (
                                            // The week stands as written. An em
                                            // dash, because the column says
                                            // "nothing" the way every other
                                            // empty figure on the product does.
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowMenu schedule={schedule} manage={manage} />
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

/**
 * Remove is offered on every schedule, unlike a workgroup's.
 *
 * What stands in the way is a team or a roster following it — rows this list
 * does not carry a count of — so the honest arrangement is to let the database
 * answer: `(schedule_id, agency_id)` is RESTRICT on both, and
 * ScheduleController::destroy translates the 23001 into a message naming what
 * still follows it rather than a 500.
 */
function RowMenu({ schedule, manage }: { schedule: Schedule; manage: boolean }) {
    if (!manage) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${schedule.name}`}>
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(schedule)} className="w-full">
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit schedule
                    </Link>
                </DropdownMenuItem>
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                            <Trash2Icon aria-hidden strokeWidth={1.5} />
                            Remove schedule
                        </DropdownMenuItem>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Remove {schedule.name}?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Its cycle goes with it. A schedule a team or anyone's roster still follows cannot be
                                removed — take those off it first.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep it</AlertDialogCancel>
                            <AlertDialogAction
                                variant="destructive"
                                onClick={() => router.delete(destroy.url(schedule))}
                            >
                                Remove schedule
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

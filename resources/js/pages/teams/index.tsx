import { Link, router } from '@inertiajs/react';
import { MoreHorizontalIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { TurnStrip } from '@/components/schedule-fields';
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
import { create, destroy, edit } from '@/routes/teams';
import type { Team } from '@/types';

const COLUMNS = {
    team: 260,
    anchor: 170,
    people: 110,
    actions: 68,
} as const;

/** What the flexible Schedule column needs for a name and a fortnight of marks. */
const FLEX_MIN = 320;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

/**
 * The agency's cohorts.
 *
 * The anchor is the column that earns its place: two teams on one schedule are
 * told apart by this date alone, and three teams seven days apart on a 21-day
 * rotation is how a hospital covers every shift every day. So the schedule's
 * own cycle sits beside it, and the dates can be read against each other.
 *
 * `People` counts the rosters carrying the team's id — there is no membership
 * table, and those rosters *are* the membership.
 */
export default function Index({ teams }: { teams: Team[] }) {
    const can = useCan();
    const manage = can('scheduling.manage');

    const addTeam = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden strokeWidth={1.5} />
                Add team
            </Link>
        </Button>
    ) : null;

    return (
        <AppLayout>
            <PageHeader
                title="Teams"
                description="A named cohort: one schedule, one anchor. Rostering people into it is what makes them members."
                actions={addTeam}
            />

            {teams.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No teams yet"
                        description="A team is a schedule and a start date. Three of them on one 21-day rotation, anchored seven days apart, cover morning, afternoon and night every day of the year."
                        action={addTeam ?? undefined}
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>Cohorts</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {teams.length.toLocaleString()}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Every team of this agency, with the schedule it follows and the date its cycle begins
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.team }}>Team</TableHead>
                                <TableHead>Schedule</TableHead>
                                <TableHead style={{ width: COLUMNS.anchor }}>Anchored</TableHead>
                                <TableHead style={{ width: COLUMNS.people }}>People</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {teams.map((team) => (
                                <TableRow key={team.id}>
                                    <TableCell className="truncate">{team.name}</TableCell>
                                    <TableCell className="max-w-0">
                                        <span className="flex min-w-0 flex-col gap-1">
                                            <span className="truncate">
                                                {team.schedule?.name ?? (
                                                    <span className="text-muted-foreground">Unknown</span>
                                                )}
                                            </span>
                                            <TurnStrip turns={team.schedule?.turns ?? []} />
                                        </span>
                                    </TableCell>
                                    {/*
                                      A `YYYY-MM-DD` string the resource sent as
                                      `toDateString()`, formatted by splitting
                                      it — never `new Date()`, which is UTC
                                      midnight and names the day before in
                                      Manila.
                                    */}
                                    <TableCell className="tabular-nums">{formatDay(team.anchor)}</TableCell>
                                    <TableCell className="tabular-nums">
                                        {team.people_count ? (
                                            team.people_count.toLocaleString()
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowMenu team={team} manage={manage} />
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
 * Remove is offered only on a team no roster has ever named — `(team_id,
 * agency_id)` is RESTRICT, and the count beside the absent item is exactly
 * what stands in the way, so the explanation is already on the row.
 *
 * Hiding it is not translating it, though: this list can be stale and the
 * route is reachable by URL, so TeamController::destroy answers the 23001 with
 * a message as well.
 */
function RowMenu({ team, manage }: { team: Team; manage: boolean }) {
    if (!manage) {
        return null;
    }

    const removable = (team.people_count ?? 0) === 0;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${team.name}`}>
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(team)} className="w-full">
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit team
                    </Link>
                </DropdownMenuItem>
                {removable && (
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                                <Trash2Icon aria-hidden strokeWidth={1.5} />
                                Remove team
                            </DropdownMenuItem>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Remove {team.name}?</AlertDialogTitle>
                                <AlertDialogDescription>
                                    The schedule it follows is untouched. A team anyone has ever been rostered into
                                    cannot be removed — its rosters are its record.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Keep it</AlertDialogCancel>
                                <AlertDialogAction
                                    variant="destructive"
                                    onClick={() => router.delete(destroy.url(team))}
                                >
                                    Remove team
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

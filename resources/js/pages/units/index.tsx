import { Link, router } from '@inertiajs/react';
import { MoreHorizontalIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { Combobox } from '@/components/combobox';
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
import { flattenUnits } from '@/lib/units';
import { cn } from '@/lib/utils';
import { index as employeesIndex } from '@/routes/employees';
import { create, destroy, edit, update } from '@/routes/units';
import type { Employee, Unit } from '@/types';

/**
 * `UnitResource` plus the two aggregates only this list asks for, which is
 * why they live here rather than on `Unit` (the split `AgencyRow` uses for
 * `users_count`).
 *
 * `people_count` is the headcount to show: open deployments, the people who
 * are in the unit now. `deployments_count` is every placement it has ever
 * held, and it decides whether Remove is offered at all — a unit named by any
 * deployment row, closed ones included, is refused by
 * `deployments_unit_id_agency_id_foreign`'s RESTRICT, and that refusal arrives
 * as a 500 rather than as a message. Two different questions, two counts.
 */
interface UnitRow extends Unit {
    people_count: number;
    deployments_count: number;
}

/**
 * One indent slot: 22px of guide, then the elbow, then the name. Enough to
 * read at a glance, small enough that a five-deep tree still fits the column.
 */
function Guides({ guides }: { guides: boolean[] }) {
    return guides.map((draw, slot) => (
        <span
            // Positional by nature: slot 0 is the outermost ancestor.
            key={slot}
            aria-hidden
            className={cn('h-full w-[22px] shrink-0', draw && 'border-edge-soft border-l')}
        />
    ));
}

/**
 * The elbow that says this unit hangs off the one above it.
 *
 * Indentation alone is ambiguous on a 44px row: the eye has to measure. A
 * hairline turns it into a drawing. The last child of a parent gets an L and
 * everyone else a T, so the vertical stroke stops where the branch does.
 *
 * MEASURED: drawn in `--rule` first, the grey the table's own row dividers
 * use, and it was invisible — #F0F0F0 at 1.14:1 disappears into the canvas at
 * one pixel wide, and the tree read as bare indentation. `--edge-soft` is the
 * token for a "non-essential inner divider" and it is what this is: structure
 * the reader should see, not a divider between rows. Not `--border` either,
 * which is the panel's own edge and would make the elbows compete with it.
 */
function Elbow({ last }: { last: boolean }) {
    return (
        <span
            aria-hidden
            className={cn(
                'border-edge-soft relative mr-2.5 w-3 shrink-0 border-l',
                last ? 'h-1/2 self-start' : 'h-full',
            )}
        >
            <span className={cn('border-edge-soft absolute left-0 w-3 border-t', last ? 'bottom-0' : 'top-1/2')} />
        </span>
    );
}

export default function Index({ units, employees }: { units: UnitRow[]; employees: Employee[] }) {
    const can = useCan();
    const manage = can('organization.manage');
    const tree = flattenUnits(units);

    /**
     * The head is chosen in the row, so the whole row is submitted: `code` and
     * `name` are required by UpdateUnitRequest, and a partial update would
     * fail validation rather than change one column. State stays on the
     * server — the request redirects back to this list, so the new head, the
     * flash toast and any other change since arrive together.
     */
    function setHead(unit: UnitRow, headId: string | null): void {
        router.put(
            update.url(unit),
            {
                parent_id: unit.parent_id ?? '',
                kind: unit.kind ?? '',
                code: unit.code,
                name: unit.name,
                head_id: headId ?? '',
            },
            { preserveScroll: true },
        );
    }

    const addUnit = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden />
                Add unit
            </Link>
        </Button>
    ) : null;

    const heads = employees.map((employee) => ({
        value: employee.id,
        label: employee.name,
        keywords: [employee.number, employee.position ?? ''],
        render: (
            <span className="flex min-w-0 items-baseline gap-2">
                <span className="truncate">{employee.name}</span>
                <span className="text-muted-foreground shrink-0 text-xs tabular-nums">{employee.number}</span>
            </span>
        ),
    }));

    return (
        <AppLayout>
            <PageHeader
                title="Units"
                description="How your agency is organised. Each unit sits under one parent, and employees are deployed into them."
                actions={addUnit}
            />

            {units.length === 0 ? (
                // The screen a freshly entered agency lands on. It teaches the
                // model rather than apologising for the absence (§5.20): what
                // a unit is in this product, in the words the agency already
                // uses for its own org chart.
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No units yet"
                        description="A unit is a box on your org chart: a department, a division, a section, an office. Add the top one first, then add what sits under it."
                        action={addUnit ?? undefined}
                    />
                </Card>
            ) : (
                <Card
                    // No `overflow` on this panel: an overflow ancestor becomes
                    // the sticky scrollport and the head silently stops pinning
                    // under the 56px bar (components.md, commit 7f2414a).
                    className="overflow-visible"
                >
                    <CardHeader>
                        <CardTitle>Structure</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {units.length} {units.length === 1 ? 'unit' : 'units'}
                        </CardDescription>
                    </CardHeader>

                    <Table>
                        <TableCaption className="sr-only mt-0">
                            Units, indented under the unit each one sits in
                        </TableCaption>
                        {/* Sticky: the head pins at `top: var(--bar-h)`, under the title bar. */}
                        <TableHeader sticky>
                            {/*
                              MEASURED: with only the last column sized, the
                              Unit column took 538 of the panel's 1210 and
                              left a 280px void between the deepest name and
                              Kind — the eye had to jump the width of a
                              sidebar to read a one-word label. Unit is
                              bounded instead and Head is the column that
                              flexes: it holds full names, and its picker's
                              hover tint has somewhere to go.
                            */}
                            <TableRow>
                                <TableHead className="w-[480px]">Unit</TableHead>
                                <TableHead className="w-[150px]">Kind</TableHead>
                                <TableHead className="w-[110px]" numeric>
                                    People
                                </TableHead>
                                <TableHead>Head</TableHead>
                                <TableHead className="w-16">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tree.map(({ unit, depth, last, children, guides }) => (
                                <TableRow key={unit.id} className="group/row">
                                    {/*
                                      The indent is padding on the cell, so the
                                      hairline elbow can sit flush against the
                                      text rather than floating in a spacer —
                                      and `first:pl-6`'s 24px gutter is what
                                      depth 0 keeps.
                                    */}
                                    <TableCell className="max-w-0 py-0">
                                        <span className="flex h-11 items-center">
                                            <Guides guides={guides} />
                                            {depth > 0 && <Elbow last={last} />}
                                            {/*
                                              MEASURED: with the code
                                              `shrink-0`, squeezing the column
                                              below its 480 took the *name* to
                                              zero and left the code sitting on
                                              the Kind column. Both truncate
                                              now, and the name keeps a floor,
                                              so a narrow viewport clips both
                                              instead of erasing the one that
                                              matters.
                                            */}
                                            <span
                                                className={cn(
                                                    'min-w-16 truncate',
                                                    depth === 0 ? 'font-medium' : 'font-normal',
                                                )}
                                            >
                                                {unit.name}
                                            </span>
                                            <span className="text-muted-foreground ml-2.5 truncate text-xs">
                                                {unit.code}
                                            </span>
                                        </span>
                                    </TableCell>
                                    {/*
                                      `kind` is the agency's own word for this
                                      level and usually agrees with the indent,
                                      so it confirms rather than announces:
                                      quiet muted text, not a badge per row.
                                    */}
                                    <TableCell className="text-muted-foreground max-w-0 truncate text-[13px]">
                                        {unit.kind ?? '—'}
                                    </TableCell>
                                    <TableCell numeric>
                                        {unit.people_count === 0 ? (
                                            <span className="text-muted-foreground">—</span>
                                        ) : (
                                            <Link
                                                href={employeesIndex({ query: { unit: unit.id } })}
                                                className="hover:text-acc-text underline-offset-2 hover:underline"
                                                aria-label={`${unit.people_count} in ${unit.name} and below`}
                                            >
                                                {unit.people_count}
                                            </Link>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {manage ? (
                                            <Combobox
                                                variant="inline"
                                                label={`Head of ${unit.name}`}
                                                value={unit.head_id}
                                                onValueChange={(value) => setHead(unit, value)}
                                                options={heads}
                                                placeholder="Choose a head"
                                                searchPlaceholder="Search employees"
                                                empty="Nobody by that name."
                                                clearLabel="No head"
                                            />
                                        ) : (
                                            (unit.head?.name ?? <span className="text-muted-foreground">—</span>)
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowMenu unit={unit} childCount={children} manage={manage} />
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
 * Edit always; Remove only where it can actually succeed.
 *
 * `units.parent_id` and `deployments.unit_id` both RESTRICT, so a unit with
 * anything under it or anyone ever placed in it is refused by the database —
 * and that refusal is a 500, not a message a form can show. So the item is
 * absent rather than offered-and-broken, which is the same rule the sidebar
 * follows for a screen that does not exist yet. The row already shows the
 * headcount and the indentation shows the children, so what is standing in
 * the way is on screen next to the missing item.
 */
function RowMenu({ unit, childCount, manage }: { unit: UnitRow; childCount: number; manage: boolean }) {
    if (!manage) {
        return null;
    }

    const removable = unit.deployments_count === 0 && childCount === 0;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${unit.name}`}>
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(unit)} className="w-full">
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit unit
                    </Link>
                </DropdownMenuItem>
                {removable && (
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                                <Trash2Icon aria-hidden strokeWidth={1.5} />
                                Remove unit
                            </DropdownMenuItem>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Remove {unit.name}?</AlertDialogTitle>
                                <AlertDialogDescription>
                                    Nobody has ever been deployed to it, so nothing is lost. You can add it again with
                                    the same code.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Keep unit</AlertDialogCancel>
                                <AlertDialogAction
                                    variant="destructive"
                                    onClick={() => router.delete(destroy.url(unit))}
                                >
                                    Remove unit
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

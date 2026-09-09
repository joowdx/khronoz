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
 * `deployments_unit_id_agency_id_foreign`'s RESTRICT. Two different questions,
 * two counts.
 */
interface UnitRow extends Unit {
    people_count: number;
    deployments_count: number;
}

/**
 * One indent slot: 22px of guide, then the elbow, then the name. Enough to
 * read at a glance, small enough that a five-deep tree still fits the column.
 *
 * Drawn in `--tick` — see `Elbow` for the contrast ruling that settles it.
 */
function Guides({ guides }: { guides: boolean[] }) {
    return guides.map((draw, slot) => (
        <span
            // Positional by nature: slot 0 is the outermost ancestor.
            key={slot}
            aria-hidden
            className={cn('h-full w-[22px] shrink-0', draw && 'border-tick border-l')}
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
 * The guides are what make a three-level tree read as a tree rather than as
 * indentation, so they are **meaning-bearing** and WCAG 1.4.11 applies: a
 * non-text graphical object needs 3:1 against what it is drawn on. MEASURED
 * against the panel ground each mode paints:
 *
 * | Token        | Light on `#FFFFFF` | Dark on `#171717` | 1.4.11 |
 * | ------------ | ------------------ | ----------------- | ------ |
 * | `--rule`     | 1.14 : 1           | 1.09 : 1          | fails  |
 * | `--edge-soft`| 1.48 : 1           | 1.42 : 1          | fails  |
 * | `--tick`     | 3.28 : 1           | 3.12 : 1          | passes |
 *
 * So `--tick`, not `--rule` (invisible at one pixel — the tree collapsed to
 * bare indentation) and not `--edge-soft` either, which reads as "non-essential
 * inner divider" and is a third of the way to legible. §11's own `--tick`
 * ruling picked those values precisely because they clear 3:1 "for an axis
 * tick", and §11 check 5 rejected `--acc` on the dark meter trough at 2.89:1
 * "because the bar is a graphical object under 1.4.11" — a tree guide is the
 * same class of object, so the project's own precedent decides it. Not
 * `--border` either, which is the panel's own edge and would make the elbows
 * compete with it.
 */
function Elbow({ last }: { last: boolean }) {
    return (
        <span
            aria-hidden
            className={cn(
                'border-tick relative mr-2.5 w-3 shrink-0 border-l',
                last ? 'h-1/2 self-start' : 'h-full',
            )}
        >
            <span className={cn('border-tick absolute left-0 w-3 border-t', last ? 'bottom-0' : 'top-1/2')} />
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
                    // `min-w-min` plus a clipped, nothing-to-clip container, so
                    // the SHELL scrolls sideways and the sticky head keeps its
                    // scrollport — see employees/index.tsx for the full note
                    // and the measurements. MEASURED here at 800: a 610px tree
                    // in a 486px card, the Head column erased and `Actions
                    // for …` off the viewport.
                    className="min-w-min overflow-visible"
                >
                    <CardHeader>
                        <CardTitle>Structure</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {units.length} {units.length === 1 ? 'unit' : 'units'}
                        </CardDescription>
                    </CardHeader>

                    {/*
                      The floor the panel's `min-w-min` inherits — see
                      employees/index.tsx for why min-content alone is not
                      enough. MEASURED at 1440, where the table wants 1126:
                      Unit 480 + Kind 150 + People 110 + Actions 68 = 808 hold
                      their declared widths, and Head — the column that flexes
                      — needs 252 of the remainder for "Maria Luisa Ocampo
                      Villanueva" to read unclipped, the longest head name in
                      the agency this was measured against. 1040 left it 16px
                      short. A no-op at 1440, where Head gets 318.
                    */}
                    <Table className="min-w-[1060px]">
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
 * anything under it or anyone ever placed in it is refused by the database. So
 * the item is absent rather than offered-and-broken, which is the same rule
 * the sidebar follows for a screen that does not exist yet. The row already
 * shows the headcount and the indentation shows the children, so what is
 * standing in the way is on screen next to the missing item.
 *
 * That is the primary defence, not the only one: hiding an action is not
 * translating a refusal, and this list can be stale (another tab moved a unit
 * under this one) or bypassed by URL. UnitController::destroy turns the 23001
 * into a flash error naming what still sits in the unit.
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

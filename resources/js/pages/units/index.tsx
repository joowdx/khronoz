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
 * Drawn in `border-input` (the design's `--edge`) — see `Elbow` for the
 * contrast ruling that settles it.
 */
function Guides({ guides }: { guides: boolean[] }) {
    return guides.map((draw, slot) => (
        <span
            // Positional by nature: slot 0 is the outermost ancestor.
            key={slot}
            aria-hidden
            className={cn('h-full w-[22px] shrink-0', draw && 'border-input border-l')}
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
 * non-text graphical object needs 3:1 against what it is drawn on — and a row
 * is a state the guides are seen in as much as rest is, since `TableRow`
 * paints `--row-hover` under the cursor. MEASURED against **both** grounds
 * the row can paint, in both modes (WCAG 2.x relative luminance):
 *
 * | Token          | Light on `--card` `#FFFFFF` | Light on `--row-hover` `#F5F5F5` | Dark on `--card` `#171717` | Dark on `--row-hover` `#1F1F1F` | 1.4.11 (worst case) |
 * | -------------- | ---------------------------- | --------------------------------- | ---------------------------- | ---------------------------------- | -------------------- |
 * | `--rule`       | 1.14 : 1                     | 1.05 : 1                          | 1.09 : 1                     | **1.00 : 1**                       | fails                |
 * | `--edge-soft`  | 1.48 : 1                     | 1.36 : 1                          | 1.42 : 1                     | 1.30 : 1                           | fails                |
 * | `--tick`       | 3.28 : 1                     | 3.01 : 1                          | 3.12 : 1                     | **2.87 : 1**                       | fails on a hovered row, dark |
 * | `--edge` (now) | 3.45 : 1                     | 3.17 : 1                          | 3.78 : 1                     | 3.48 : 1                           | **passes everywhere** |
 *
 * (Fix round 3: the dark `--rule` / `--edge-soft` cells above were previously
 * 1.20/1.09 and 1.57/1.42 — figures for `--canvas`/`--side`, mislabelled onto
 * `--card`/`--row-hover`. Recomputed from `docs/design/mockups/tokens.css`.
 * Dark `--rule` and dark `--row-hover` are both literally `#1F1F1F`, so that
 * cell is not "low contrast," it is the same colour as its own background.)
 *
 * `--tick` was the previous token here (§11's own ruling: it clears 3:1 "for
 * an axis tick", and it does — at rest, against `--card`. It was never
 * measured against `--row-hover`). Against a hovered row in dark mode it
 * measures 2.87:1 — the *same figure* §11 check 5 rejected `--acc` at on the
 * dark meter trough, "because the bar is a graphical object under 1.4.11."
 * The ruling that put the guides on a token in the first place was that they
 * are meaning-bearing; a hovered row is a state they are seen in, so the
 * requirement follows them there too, and the binding ground is whichever one
 * is worse — the hover ground, not the rest one.
 *
 * `--edge` clears 3:1 against both grounds in both modes, worst case 3.17:1
 * (light, hovered). It is exposed as the Tailwind utility `border-input`
 * (`.ai/rules` and `08-interface.md` §9.4 trap 2: `--input` *is* `--edge`,
 * the control border, under the shadcn-mapped name) rather than a fresh
 * `--color-edge` entry — no new token. Not `--rule` (invisible at one pixel —
 * the tree collapsed to bare indentation), not `--edge-soft` (reads as
 * "non-essential inner divider" and is a third of the way to legible), and
 * not `--border` either, which is the panel's own edge and would make the
 * elbows compete with it.
 */
function Elbow({ last }: { last: boolean }) {
    return (
        <span
            aria-hidden
            className={cn(
                'border-input relative mr-2.5 w-3 shrink-0 border-l',
                last ? 'h-1/2 self-start' : 'h-full',
            )}
        >
            <span className={cn('border-input absolute left-0 w-3 border-t', last ? 'bottom-0' : 'top-1/2')} />
        </span>
    );
}

/**
 * The declared width for every fixed column. Both the `TableHead`s below and
 * the table's `min-w` floor (`TABLE_MIN_WIDTH`) read from this one object, so
 * the floor cannot silently drift out of step with the columns the way a
 * hand-typed `min-w-[1060px]` did (fix round 2) — see employees/index.tsx's
 * `COLUMNS` for the fuller derivation note and the table-layout:auto caveat.
 * Unlike employees/index.tsx, MEASURED here shows Unit/Kind/People holding
 * their declared widths exactly at both 800 and 1440 — this page's overflow
 * comes entirely from Head, the flexible column, not from the fixed ones
 * being squeezed. `actions` is 68 for the same reason it is on
 * employees/index.tsx: table-layout:auto never renders it narrower than
 * that, on either page, at any width tried.
 */
const COLUMNS = {
    unit: 480,
    kind: 150,
    people: 110,
    actions: 68,
} as const;

/**
 * Not a column: what the *flexible* Head column needs for the longest real
 * head name in the agency this was measured against ("Maria Luisa Ocampo
 * Villanueva"), at 1440. There is no `w-[…]` for Head — it takes whatever
 * `COLUMNS` leaves.
 */
const FLEX_MIN = 252;

/** A no-op at 1440, where the table wants 1126 regardless and Head gets 318. */
const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

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
                      The floor the panel's `min-w-min` inherits, derived from
                      `COLUMNS` and `FLEX_MIN` above rather than hand-typed —
                      see employees/index.tsx for why min-content alone is not
                      enough and for the table-layout:auto caveat.
                    */}
                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
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
                                <TableHead style={{ width: COLUMNS.unit }}>Unit</TableHead>
                                <TableHead style={{ width: COLUMNS.kind }}>Kind</TableHead>
                                <TableHead style={{ width: COLUMNS.people }} numeric>
                                    People
                                </TableHead>
                                <TableHead>Head</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
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

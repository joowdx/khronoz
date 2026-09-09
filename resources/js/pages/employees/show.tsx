import { Form, Link } from '@inertiajs/react';
import { ArrowRightLeftIcon, PencilIcon } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { Combobox } from '@/components/combobox';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { Badge, StatusPill } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { addDay, formatDay, laterDay, manilaToday } from '@/lib/dates';
import { flattenUnits, unitPath } from '@/lib/units';
import { cn } from '@/lib/utils';
import { edit, index } from '@/routes/employees';
import { store } from '@/routes/employees/deployments';
import type { Employee, Unit } from '@/types';

/** Sections divided by a rule with 32 either side, never by a box (§1 rule 3) — the same rhythm the dashboard reads in. */
function Stack({ children }: { children: ReactNode }) {
    return <div className="[&>section+section]:border-border [&>section+section]:mt-8 [&>section+section]:border-t [&>section+section]:pt-8">{children}</div>;
}

function SectionHead({ title, action }: { title: string; action?: ReactNode }) {
    return (
        <div className="border-border flex min-h-9 items-center gap-3 border-b pb-3">
            <h2 className="text-sm leading-5 font-semibold tracking-[-0.002em]">{title}</h2>
            {action && <span className="ml-auto">{action}</span>}
        </div>
    );
}

/**
 * §6.3's leader-dot row: a label, a run of dots across the gap, and the value
 * at the right in 600. It is the design's one label-and-value shape, and for a
 * personnel record it is the right one — this is how the paper 201 file it
 * replaces is laid out.
 *
 * `.kv + .kv` carries the rule, not `.kv`, so a list closes without a trailing
 * hairline. Marked up as a `<dl>` group: a `<div>` between `<dl>` and its
 * `<dt>`/`<dd>` is valid and is what lets the dots sit between them.
 */
function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="[&+&]:border-rule flex min-h-[38px] items-baseline gap-2 pt-[9px] [&+&]:border-t">
            <dt className="shrink-0 text-sm leading-5">{label}</dt>
            <span
                aria-hidden
                className="mx-0.5 h-px min-w-4 flex-1 self-center bg-[radial-gradient(circle_at_1px_1px,var(--dot)_1px,transparent_1.2px)] bg-[length:5px_2px] bg-repeat-x"
            />
            <dd className="text-right text-sm leading-5 font-semibold tabular-nums">{children}</dd>
        </div>
    );
}

/** A value the record does not hold. Muted and unemphasised, so an empty field never reads as a filled one. */
function Blank({ children = 'Not recorded' }: { children?: string }) {
    return <span className="text-muted-foreground font-normal">{children}</span>;
}

const SEX = { male: 'Male', female: 'Female' } as const;

export default function Show({ employee, units }: { employee: Employee; units: Unit[] }) {
    const can = useCan();
    const manage = can('organization.manage');
    const [moving, setMoving] = useState(false);

    const current = employee.current_deployment ?? null;
    const history = employee.deployments ?? [];
    const deployed = current !== null;

    // Always the outline variant, deployed or not. §5.2 gives a page one
    // primary action and puts it in the heading row, which here is Edit
    // record; a second filled button in the section below would read as its
    // equal. MEASURED the other way first — two outline buttons 46px apart
    // read as a pair of equals and neither led, so the heading row's took the
    // fill and this one kept the border.
    const move = manage ? (
        <Button variant="outline" onClick={() => setMoving(true)}>
            <ArrowRightLeftIcon aria-hidden strokeWidth={1.5} />
            {deployed ? 'Move to another unit' : 'Deploy to a unit'}
        </Button>
    ) : null;

    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Employees', href: index().url }}
                title={employee.name}
                description={
                    <>
                        <span className="tabular-nums">Employee no. {employee.number}</span>
                        {employee.separated_at !== null ? (
                            <StatusPill variant="secondary">Separated</StatusPill>
                        ) : employee.exempt ? (
                            <StatusPill variant="secondary">Exempt</StatusPill>
                        ) : (
                            <StatusPill variant="positive">Active</StatusPill>
                        )}
                    </>
                }
                actions={
                    manage ? (
                        <Button asChild>
                            <Link href={edit(employee)}>
                                <PencilIcon aria-hidden strokeWidth={1.5} />
                                Edit record
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <Stack>
                {/*
                  Where they work comes first, above every other fact on the
                  page: it is the one thing about an employee that changes, the
                  one thing every schedule and daily time record hangs off, and
                  the only thing on this screen with an action attached.
                */}
                <section>
                    <SectionHead title="Where they work" action={deployed ? move : undefined} />
                    {current?.unit ? (
                        <div className="flex flex-wrap items-start justify-between gap-4 pt-4">
                            <div className="min-w-0">
                                <p className="flex items-center gap-2.5 text-lg leading-6 font-semibold">
                                    <span className="truncate">{current.unit.name}</span>
                                    {current.unit.kind && <Badge variant="outline">{current.unit.kind}</Badge>}
                                </p>
                                {/* Where the unit sits, not just what it is
                                    called: two divisions can share a name and
                                    the ancestry is what tells them apart. */}
                                <p className="text-muted-foreground pt-1 text-[13px] leading-[18px]">
                                    {unitPath(current.unit, units)}
                                </p>
                            </div>
                            <p className="text-[13px] leading-[18px] font-medium tabular-nums">
                                Since {formatDay(current.starts)}
                            </p>
                        </div>
                    ) : employee.separated_at !== null ? (
                        // Someone who has left has no open placement and needs
                        // none. Offering Deploy to a unit here would be
                        // inviting an action that means nothing — the third of
                        // §5.20's cases, said plainly and with no action
                        // attached. Their history is still below, because that
                        // is what anyone opening a former employee's profile
                        // came for.
                        <EmptyState
                            className="pb-0"
                            title="No open placement"
                            description={`They left on ${formatDay(employee.separated_at)}. Every unit they worked in is below.`}
                        />
                    ) : (
                        <EmptyState
                            className="pb-0"
                            title="Not deployed yet"
                            description="A deployment says which unit this person belongs to, and from when. Their schedule and their daily time record both follow the unit they are in."
                            action={move ?? undefined}
                        />
                    )}
                </section>

                {/* §6.3's split: two subjects side by side, a 48 gutter, and the rule on the right column. */}
                <section className="grid gap-12 lg:grid-cols-2">
                    <div>
                        <SectionHead title="Personal" />
                        <dl>
                            <Fact label="Date of birth">
                                {employee.birthdate ? formatDay(employee.birthdate) : <Blank />}
                            </Fact>
                            <Fact label="Sex">{employee.sex ? SEX[employee.sex] : <Blank />}</Fact>
                            <Fact label="Email">
                                {employee.email ?? <Blank />}
                            </Fact>
                            <Fact label="Mobile">{employee.mobile ?? <Blank />}</Fact>
                        </dl>
                    </div>
                    <div className="lg:border-border lg:border-l lg:pl-12">
                        <SectionHead title="Employment" />
                        <dl>
                            <Fact label="Position">{employee.position ?? <Blank />}</Fact>
                            <Fact label="Hired on">{formatDay(employee.hired_at)}</Fact>
                            <Fact label="Separated on">
                                {employee.separated_at ? (
                                    formatDay(employee.separated_at)
                                ) : (
                                    <Blank>Still employed</Blank>
                                )}
                            </Fact>
                            <Fact label="Daily time record">
                                {employee.exempt ? <Blank>Not expected</Blank> : 'Expected'}
                            </Fact>
                            <Fact label="Tags">
                                {employee.tags.length === 0 ? (
                                    <Blank>None</Blank>
                                ) : (
                                    <span className="flex flex-wrap justify-end gap-1.5">
                                        {employee.tags.map((tag) => (
                                            <Badge key={tag} variant="secondary">
                                                {tag}
                                            </Badge>
                                        ))}
                                    </span>
                                )}
                            </Fact>
                        </dl>
                    </div>
                </section>

                {/*
                  A table, not a decorative timeline (task-6-brief.md): these
                  rows are the evidence behind a DTR, they get compared against
                  paper, and a reader needs the dates in a column they can run
                  a finger down.
                */}
                <section>
                    <Card className="overflow-visible">
                        <CardHeader>
                            <CardTitle>Deployment history</CardTitle>
                            <CardDescription className="ml-auto tabular-nums">
                                {history.length} {history.length === 1 ? 'placement' : 'placements'}
                            </CardDescription>
                        </CardHeader>
                        <Table>
                            <TableCaption className="sr-only mt-0">
                                Every unit {employee.name} has been deployed to
                            </TableCaption>
                            <TableHeader sticky>
                                <TableRow>
                                    <TableHead>Unit</TableHead>
                                    <TableHead className="w-[200px]">From</TableHead>
                                    <TableHead className="w-[200px]">Until</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {history.length === 0 ? (
                                    <TableRow className="hover:[&>td]:bg-transparent">
                                        <TableCell colSpan={3} className="h-auto border-b-0 py-2 whitespace-normal">
                                            <EmptyState
                                                className="py-6"
                                                title="Nothing recorded yet"
                                                description="Every move is kept here, so a daily time record can always be traced to the unit that filed it."
                                            />
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    history.map((deployment) => (
                                        <TableRow
                                            key={deployment.id}
                                            // The open placement takes the
                                            // selected tint (§5.13), and its
                                            // Until cell says so in a word —
                                            // colour is never the only cue.
                                            data-state={deployment.ends === null ? 'selected' : undefined}
                                        >
                                            <TableCell className="max-w-0 truncate">
                                                {deployment.unit ? (
                                                    <span className="flex items-center gap-2.5">
                                                        <span className="truncate font-medium">
                                                            {deployment.unit.name}
                                                        </span>
                                                        {deployment.unit.kind && (
                                                            <span className="text-muted-foreground shrink-0 text-xs">
                                                                {deployment.unit.kind}
                                                            </span>
                                                        )}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">Unit removed</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {formatDay(deployment.starts)}
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    'tabular-nums',
                                                    deployment.ends === null && 'text-acc-text font-medium',
                                                )}
                                            >
                                                {deployment.ends === null ? 'Present' : formatDay(deployment.ends)}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </Card>
                </section>
            </Stack>

            {manage && <MoveSheet employee={employee} units={units} open={moving} onOpenChange={setMoving} />}
        </AppLayout>
    );
}

/**
 * §5.15's sheet: a focused task against context that must stay visible. The
 * history behind it is exactly what someone checks before choosing a date, so
 * this is a sheet rather than a dialog — §5.16 reserves the centred box for
 * confirming something destructive.
 *
 * Two fields and no overlap pre-check of its own. `deployments_no_overlap` (an
 * EXCLUDE USING gist constraint) and `deployments_dates_ordered` (a CHECK) are
 * the last word on whether a date collides, and EmployeeDeploymentController
 * turns both refusals into an error on `starts` — so a collision arrives on
 * the label row like any other validation failure and the sheet stays open on
 * the values that caused it.
 */
function MoveSheet({
    employee,
    units,
    open,
    onOpenChange,
}: {
    employee: Employee;
    units: Unit[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [unit, setUnit] = useState<string | null>(null);
    const current = employee.current_deployment ?? null;
    const tree = flattenUnits(units);

    /*
     * The earliest date this move can carry, and the field enforces exactly
     * it. A move closes the open placement the day before the new one starts,
     * so anything on or before that placement's own `starts` would leave it
     * ending before it began and `deployments_dates_ordered` refuses the
     * whole transaction.
     *
     * MEASURED: with `min` at `hired_at` alone, an employee hired in 2019
     * whose current placement began in 2026 was offered a seven-year window
     * in which every single date was fatal — and before the controller
     * translated 23514 it answered with a 500. The constraint is still what
     * decides (R16: no pre-check, and a concurrent move can still make a
     * legal-looking date illegal between render and submit); this is the
     * control no longer inviting the refusal.
     */
    const earliest = current === null ? employee.hired_at : laterDay(employee.hired_at, addDay(current.starts));

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent aria-describedby="move-what-happens">
                <Form
                    {...store.form(employee)}
                    className="flex h-full min-h-0 flex-col"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>{current ? 'Move to another unit' : 'Deploy to a unit'}</SheetTitle>
                            </SheetHeader>

                            <div className="min-h-0 flex-1 overflow-auto p-5">
                                <SheetDescription id="move-what-happens" className="text-[13px] leading-[18px]">
                                    {current
                                        ? `${employee.name} leaves ${current.unit?.name ?? 'their current unit'} the day before this date, and joins the new one on it.`
                                        : `${employee.name} joins the unit on this date. Nothing before it changes.`}
                                </SheetDescription>

                                <Field className="mt-5" label="Unit" htmlFor="unit_id" error={errors.unit_id}>
                                    {({ id, invalid, describedBy }) => (
                                        <Combobox
                                            id={id}
                                            name="unit_id"
                                            value={unit}
                                            onValueChange={setUnit}
                                            invalid={invalid}
                                            describedBy={describedBy}
                                            placeholder="Choose a unit"
                                            searchPlaceholder="Search units"
                                            empty="No unit by that name."
                                            options={tree.map(({ unit: option, depth }) => ({
                                                value: option.id,
                                                label: option.name,
                                                keywords: [option.code, option.kind ?? ''],
                                                trigger: option.name,
                                                disabled: option.id === current?.unit?.id,
                                                render: (
                                                    <span
                                                        className="flex min-w-0 items-center"
                                                        style={{ paddingLeft: depth * 14 }}
                                                    >
                                                        <span className="truncate">{option.name}</span>
                                                        <span className="text-muted-foreground ml-2 shrink-0 text-xs">
                                                            {option.code}
                                                        </span>
                                                    </span>
                                                ),
                                            }))}
                                        />
                                    )}
                                </Field>

                                <Field
                                    className="mt-4"
                                    label="Effective from"
                                    htmlFor="starts"
                                    error={errors.starts}
                                    // The hint says what the control enforces,
                                    // and which fact set the floor — a clerk
                                    // who cannot pick last month should be
                                    // able to read why without guessing.
                                    hint={
                                        earliest === employee.hired_at
                                            ? `On or after ${formatDay(employee.hired_at)}, the day they were hired.`
                                            : `On or after ${formatDay(earliest)}, the day after the current placement began.`
                                    }
                                >
                                    {({ id, invalid, describedBy }) => (
                                        <Input
                                            id={id}
                                            name="starts"
                                            type="date"
                                            defaultValue={laterDay(manilaToday(), earliest)}
                                            min={earliest}
                                            max={employee.separated_at ?? undefined}
                                            aria-invalid={invalid}
                                            aria-describedby={describedBy}
                                        />
                                    )}
                                </Field>
                            </div>

                            <SheetFooter>
                                {/*
                                  Not disabled until a unit is picked. §6.1
                                  makes the label row the validation
                                  mechanism, and a disabled submit leaves the
                                  tab order while explaining nothing —
                                  submitting empty puts `Required` on the Unit
                                  label row, which is both reachable and
                                  specific.
                                */}
                                <Button type="submit" disabled={processing}>
                                    {current ? 'Move employee' : 'Deploy employee'}
                                </Button>
                                <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                                    Cancel
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}

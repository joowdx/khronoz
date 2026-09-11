import { Form, Link } from '@inertiajs/react';
import { ArrowRightLeftIcon, PencilIcon } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { Combobox } from '@/components/combobox';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { Badge, StatusPill } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { addDay, formatDay, laterDay, manilaToday } from '@/lib/dates';
import { flattenWorkgroups, workgroupPath } from '@/lib/workgroups';
import { cn } from '@/lib/utils';
import { edit, index } from '@/routes/employees';
import { store, update } from '@/routes/employees/deployments';
import type { Deployment, Employee, Workgroup } from '@/types';

/** Sections divided by a rule with 32 either side, never by a box (§1 rule 3) — the same rhythm the dashboard reads in. */
function Stack({ children }: { children: ReactNode }) {
    return (
        <div className="[&>section+section]:border-border [&>section+section]:mt-8 [&>section+section]:border-t [&>section+section]:pt-8">
            {children}
        </div>
    );
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

export default function Show({ employee, workgroups }: { employee: Employee; workgroups: Workgroup[] }) {
    const can = useCan();
    const manage = can('organization.manage');
    const [moving, setMoving] = useState(false);
    const [ending, setEnding] = useState(false);

    const current = employee.current_deployment ?? null;
    const history = employee.deployments ?? [];
    const lastEnd = history[0]?.ends ?? null;
    const placementCount = history.filter((deployment) => deployment.parent_id === null).length;
    const reassignmentCount = history.length - placementCount;
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
            {deployed ? 'Move to another workgroup' : 'Deploy to a workgroup'}
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
                        {employee.exempt ? (
                            <StatusPill variant="secondary">Exempt</StatusPill>
                        ) : deployed ? (
                            <StatusPill variant="positive">Active</StatusPill>
                        ) : (
                            <StatusPill variant="secondary">No open placement</StatusPill>
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
                    <SectionHead
                        title="Where they work"
                        action={
                            deployed ? (
                                <div className="flex flex-wrap gap-2">
                                    {move}
                                    {manage && (
                                        <Button variant="outline" onClick={() => setEnding(true)}>
                                            End placement
                                        </Button>
                                    )}
                                </div>
                            ) : undefined
                        }
                    />
                    {current?.workgroup ? (
                        <div className="flex flex-wrap items-start justify-between gap-4 pt-4">
                            <div className="min-w-0">
                                <p className="flex items-center gap-2.5 text-lg leading-6 font-semibold">
                                    <span className="truncate">{current.workgroup.name}</span>
                                    {current.workgroup.kind && (
                                        <Badge variant="outline">{current.workgroup.kind}</Badge>
                                    )}
                                </p>
                                {/* Where the workgroup sits, not just what it is
                                    called: two divisions can share a name and
                                    the ancestry is what tells them apart. */}
                                <p className="text-muted-foreground pt-1 text-[13px] leading-[18px]">
                                    {workgroupPath(current.workgroup, workgroups)}
                                </p>
                            </div>
                            <p className="text-[13px] leading-[18px] font-medium tabular-nums">
                                Since {formatDay(current.starts)}
                            </p>
                        </div>
                    ) : (
                        <EmptyState
                            className="pb-0"
                            title={history.length > 0 ? 'No open placement' : 'Not deployed yet'}
                            description={
                                lastEnd !== null
                                    ? `Their last placement ended on ${formatDay(lastEnd)}. Deploy them again to start a new placement; their history stays below.`
                                    : 'A deployment records which workgroup this person belongs to and when their placement starts.'
                            }
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
                            <Fact label="Sex">{employee.sex?.label ?? <Blank />}</Fact>
                            <Fact label="Email">{employee.email ?? <Blank />}</Fact>
                            <Fact label="Mobile">{employee.mobile ?? <Blank />}</Fact>
                        </dl>
                    </div>
                    <div className="lg:border-border lg:border-l lg:pl-12">
                        <SectionHead title="Employment" />
                        <dl>
                            <Fact label="Position">{employee.position ?? <Blank />}</Fact>
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
                                {/*
                                  Counted separately because they are not the
                                  same thing: `history.length` would call a
                                  reassignment a placement, and this card's
                                  own rows now distinguish them.
                                */}
                                {placementCount} {placementCount === 1 ? 'placement' : 'placements'}
                                {reassignmentCount > 0 &&
                                    ` · ${reassignmentCount} ${reassignmentCount === 1 ? 'reassignment' : 'reassignments'}`}
                            </CardDescription>
                        </CardHeader>
                        <Table>
                            <TableCaption className="sr-only mt-0">
                                Every workgroup {employee.name} has been deployed to
                            </TableCaption>
                            <TableHeader sticky>
                                <TableRow>
                                    <TableHead>Workgroup</TableHead>
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
                                                description="Every move is kept here, so a daily time record can always be traced to the workgroup that filed it."
                                            />
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    nestDeployments(history).map(({ deployment, nested }) => (
                                        <TableRow
                                            key={deployment.id}
                                            // The open placement takes the
                                            // selected tint (§5.13), and its
                                            // Until cell says so in a word —
                                            // colour is never the only cue.
                                            data-state={deployment.ends === null ? 'selected' : undefined}
                                        >
                                            <TableCell className="max-w-0 truncate">
                                                {deployment.workgroup ? (
                                                    <span
                                                        className="flex items-center gap-2.5"
                                                        style={{ paddingLeft: nested ? 14 : 0 }}
                                                    >
                                                        <span className="truncate font-medium">
                                                            {deployment.workgroup.name}
                                                        </span>
                                                        {/*
                                                          The indent alone would
                                                          be colour-of-position:
                                                          a word carries the
                                                          meaning, since a
                                                          reassignment overlapping
                                                          its placement is
                                                          otherwise two rows
                                                          covering one date for no
                                                          visible reason.
                                                        */}
                                                        {nested && (
                                                            <span className="text-muted-foreground shrink-0 text-xs">
                                                                reassigned
                                                            </span>
                                                        )}
                                                        {!nested && deployment.workgroup.kind && (
                                                            <span className="text-muted-foreground shrink-0 text-xs">
                                                                {deployment.workgroup.kind}
                                                            </span>
                                                        )}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">Workgroup removed</span>
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

            {manage && <MoveSheet employee={employee} workgroups={workgroups} open={moving} onOpenChange={setMoving} />}
            {manage && current && (
                <EndSheet employee={employee} placement={current} open={ending} onOpenChange={setEnding} />
            )}
        </AppLayout>
    );
}

/**
 * Order the history so a reassignment sits directly under the placement it
 * departs from, rather than floating by date among rows it overlaps.
 *
 * The controller already sorts by `starts` descending. A reassignment always
 * begins on or after its parent's start, so sorting alone puts it *above* the
 * row it belongs to — which reads as an unexplained second placement on the
 * same dates. This regroups without re-sorting: each placement keeps its
 * position and its own reassignments follow it, newest first.
 *
 * A reassignment whose parent is not in the list is emitted at its own
 * position rather than dropped, so a filtered or paginated history can never
 * silently lose a row.
 */
function nestDeployments(history: Deployment[]): { deployment: Deployment; nested: boolean }[] {
    const placements = history.filter((deployment) => deployment.parent_id === null);
    const shown = new Set<string>();

    const rows = placements.flatMap((placement) => {
        shown.add(placement.id);

        const nested = history.filter((deployment) => deployment.parent_id === placement.id);
        nested.forEach((deployment) => shown.add(deployment.id));

        return [
            { deployment: placement, nested: false },
            ...nested.map((deployment) => ({ deployment, nested: true })),
        ];
    });

    const orphans = history
        .filter((deployment) => !shown.has(deployment.id))
        .map((deployment) => ({ deployment, nested: true }));

    return [...rows, ...orphans];
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
    workgroups,
    open,
    onOpenChange,
}: {
    employee: Employee;
    workgroups: Workgroup[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [workgroup, setWorkgroup] = useState<string | null>(null);
    const [reassigning, setReassigning] = useState(false);
    const current = employee.current_deployment ?? null;
    const tree = flattenWorkgroups(workgroups);

    // A transfer ends the current placement the day before the new one starts,
    // so it cannot begin on or before the day that placement began. A
    // reassignment closes nothing and nests inside it, so it may begin on that
    // first day — the bound is the placement's own start, not the day after.
    const earliest = current === null ? undefined : reassigning ? current.starts : addDay(current.starts);

    // There is nothing to nest a reassignment inside without an open
    // placement, and the request refuses it. The box is disabled rather than
    // hidden so the option is discoverable, and the hint says why.
    const canReassign = current !== null;

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
                                <SheetTitle>
                                    {reassigning
                                        ? 'Reassign elsewhere'
                                        : current
                                          ? 'Move to another workgroup'
                                          : 'Deploy to a workgroup'}
                                </SheetTitle>
                            </SheetHeader>

                            <div className="min-h-0 flex-1 overflow-auto p-5">
                                {/*
                                  The description states the consequence for
                                  the verb currently selected, because the two
                                  differ in exactly the thing a clerk cannot
                                  see: whether the current placement closes.
                                  A reassignment leaving it open is the whole
                                  point of the arrangement — the plantilla item
                                  never moved.
                                */}
                                <SheetDescription id="move-what-happens" className="text-[13px] leading-[18px]">
                                    {reassigning
                                        ? `${employee.name} works in the new workgroup for this period. Their placement in ${current?.workgroup?.name ?? 'their current workgroup'} stays open, because the plantilla item does not move.`
                                        : current
                                          ? `${employee.name} leaves ${current.workgroup?.name ?? 'their current workgroup'} the day before this date, and joins the new one on it.`
                                          : `${employee.name} joins the workgroup on this date. Nothing before it changes.`}
                                </SheetDescription>

                                <Field
                                    className="mt-5"
                                    label="Workgroup"
                                    htmlFor="workgroup_id"
                                    error={errors.workgroup_id}
                                >
                                    {({ id, invalid, describedBy }) => (
                                        <Combobox
                                            id={id}
                                            name="workgroup_id"
                                            value={workgroup}
                                            onValueChange={setWorkgroup}
                                            invalid={invalid}
                                            describedBy={describedBy}
                                            placeholder="Choose a workgroup"
                                            searchPlaceholder="Search workgroups"
                                            empty="No workgroup by that name."
                                            options={tree.map(({ workgroup: option, depth }) => ({
                                                value: option.id,
                                                label: option.name,
                                                keywords: [option.code, option.kind ?? ''],
                                                trigger: option.name,
                                                disabled: option.id === current?.workgroup?.id,
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

                                {/*
                                  A checkbox and not a segmented control:
                                  §5.10 caps that pattern at two uses, and
                                  §5.9 sends anything a form submits here. The
                                  hidden `0` companion is what makes the
                                  unchecked state reach the server as false
                                  rather than as an absent key.
                                */}
                                <div className="mt-4">
                                    <input type="hidden" name="reassignment" value="0" />
                                    <label className="flex items-start gap-2.5 text-sm">
                                        <Checkbox
                                            name="reassignment"
                                            value="1"
                                            checked={reassigning}
                                            disabled={!canReassign}
                                            onCheckedChange={(checked) => setReassigning(checked === true)}
                                            aria-describedby="reassignment-hint"
                                            className="mt-0.5"
                                        />
                                        <span>
                                            <span
                                                className={cn('font-medium', !canReassign && 'text-muted-foreground')}
                                            >
                                                Reassignment or detail
                                            </span>
                                            <span
                                                id="reassignment-hint"
                                                className="text-muted-foreground mt-0.5 block text-[13px] leading-[18px]"
                                            >
                                                {canReassign
                                                    ? 'The person moves; the plantilla item stays. Leave this clear for a transfer, which closes the current placement.'
                                                    : 'Available once this employee has an open placement to be reassigned from.'}
                                            </span>
                                        </span>
                                    </label>
                                    {errors.reassignment && (
                                        <p className="text-fault mt-1.5 text-[13px]">{errors.reassignment}</p>
                                    )}
                                </div>

                                <Field
                                    className="mt-4"
                                    label="Effective from"
                                    htmlFor="starts"
                                    error={errors.starts}
                                    hint={
                                        earliest
                                            ? reassigning
                                                ? `On or after ${formatDay(earliest)}, the day the current placement began.`
                                                : `On or after ${formatDay(earliest)}, the day after the current placement began.`
                                            : 'Choose the first day in this workgroup. Previous placements stay in the history.'
                                    }
                                >
                                    {({ id, invalid, describedBy }) => (
                                        <Input
                                            id={id}
                                            name="starts"
                                            type="date"
                                            defaultValue={earliest ? laterDay(manilaToday(), earliest) : manilaToday()}
                                            min={earliest}
                                            aria-invalid={invalid}
                                            aria-describedby={describedBy}
                                        />
                                    )}
                                </Field>

                                {/*
                                  Optional on both verbs and for different
                                  reasons: a reassignment normally runs for a
                                  stated period, while a placement carries one
                                  only when the appointment is fixed-term —
                                  contractual, casual or co-terminous.
                                */}
                                <Field
                                    className="mt-4"
                                    label="Until"
                                    htmlFor="ends"
                                    error={errors.ends}
                                    hint={
                                        reassigning
                                            ? 'The last day away. Leave blank for an open-ended reassignment, which needs the placement to be open-ended too.'
                                            : 'Optional. Set it only for a fixed-term appointment; leave blank for an open placement.'
                                    }
                                >
                                    {({ id, invalid, describedBy }) => (
                                        <Input
                                            id={id}
                                            name="ends"
                                            type="date"
                                            min={earliest}
                                            aria-invalid={invalid}
                                            aria-describedby={describedBy}
                                        />
                                    )}
                                </Field>
                            </div>

                            <SheetFooter>
                                {/*
                                  Not disabled until a workgroup is picked. §6.1
                                  makes the label row the validation
                                  mechanism, and a disabled submit leaves the
                                  tab order while explaining nothing —
                                  submitting empty puts `Required` on the Workgroup
                                  label row, which is both reachable and
                                  specific.
                                */}
                                <Button type="submit" disabled={processing}>
                                    {reassigning ? 'Reassign employee' : current ? 'Move employee' : 'Deploy employee'}
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

/**
 * Ending keeps the selected day in the placement and opens no replacement.
 *
 * `deployment` and `expects` are hidden fields, and they are the reason this
 * takes the whole placement rather than just its start date. The endpoint
 * closes `WHERE id = :deployment AND ends IS NOT DISTINCT FROM :expects`, so
 * a form rendered before someone else transferred or corrected this person
 * affects nothing instead of closing whichever row happens to be open when it
 * lands. `expects` is the row's `ends` as this page saw it — empty for an
 * open placement.
 */
function EndSheet({
    employee,
    placement,
    open,
    onOpenChange,
}: {
    employee: Employee;
    placement: Deployment;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const starts = placement.starts;
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent aria-describedby="end-what-happens">
                <Form
                    {...update.form(employee)}
                    className="flex h-full min-h-0 flex-col"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ errors, processing }) => (
                        <>
                            <SheetHeader>
                                <SheetTitle>End placement</SheetTitle>
                            </SheetHeader>
                            <input type="hidden" name="deployment" value={placement.id} />
                            <input type="hidden" name="expects" value={placement.ends ?? ''} />
                            <div className="min-h-0 flex-1 overflow-auto p-5">
                                <SheetDescription id="end-what-happens" className="text-[13px] leading-[18px]">
                                    {employee.name}'s placement includes this last day. No new placement is opened, and
                                    their history is kept.
                                </SheetDescription>
                                <Field
                                    className="mt-5"
                                    label="Last day"
                                    htmlFor="ends"
                                    error={errors.ends}
                                    hint={`On or after ${formatDay(starts)}, the day this placement began.`}
                                >
                                    {({ id, invalid, describedBy }) => (
                                        <Input
                                            id={id}
                                            name="ends"
                                            type="date"
                                            min={starts}
                                            defaultValue={laterDay(manilaToday(), starts)}
                                            aria-invalid={invalid}
                                            aria-describedby={describedBy}
                                        />
                                    )}
                                </Field>
                            </div>
                            <SheetFooter>
                                <Button type="submit" disabled={processing}>
                                    End placement
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

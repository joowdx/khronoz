import { Form, Link, router } from '@inertiajs/react';
import { MoreHorizontalIcon, PencilIcon, PlusIcon, Trash2Icon, UploadIcon } from 'lucide-react';
import { useState } from 'react';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDay } from '@/lib/dates';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { create, destroy, edit } from '@/routes/terminals';
import { store as importTimelogs } from '@/routes/terminals/syncs';
import type { Terminal } from '@/types';

/**
 * `TerminalResource` plus the two aggregates only this list asks for, which is
 * why they live here rather than on `Terminal` (.ai/rules/resources.md).
 *
 * `enrolled_count` is how many people this device can identify **today** —
 * enrollments covering the current date. It is the most useful thing this list
 * can say about a newly registered terminal, because a device with none cannot
 * resolve a single punch it captures; they arrive and sit unresolved.
 *
 * `timelogs_count` is every punch it has ever captured, and it decides whether
 * Remove is offered: every foreign key into `terminals` RESTRICTs (decision
 * 41), so a device that has captured anything cannot be deleted at all.
 */
interface TerminalRow extends Terminal {
    enrolled_count: number;
    timelogs_count: number;
    /**
     * When punches were last successfully imported — from the `syncs` row, not
     * from `synced_at`. Decision 40 forbids an import touching `synced_at`
     * (a file is not a device read), so that column is null forever on a
     * file-import terminal and would read "never" for a device imported this
     * morning. Absent, not null, when the query did not ask for it.
     */
    last_import_at?: string | null;
}

/**
 * Fixed column widths, summed into the table's floor rather than hand-typed —
 * the derivation and the `table-layout: auto` caveat are in employees/index.tsx.
 */
const COLUMNS = {
    code: 90,
    name: 300,
    imported: 150,
    enrolled: 110,
    punches: 120,
    actions: 68,
} as const;

/**
 * What the flexible **Where** column needs for a realistic workgroup name.
 *
 * Where flexes and Name is bounded, which is the opposite of the first
 * attempt and the same correction workgroups/index already carries. MEASURED
 * at 1440 with Name flexible: "Lobby entrance" sat in a 520px column and left
 * a ~400px void before the Where label — the eye had to cross a sidebar's
 * width to read a two-word place name. `pages.md` records the identical
 * failure on workgroups/index (538 of 1210, a 280px void) and the identical
 * fix: bound the name, let a later text column take the slack.
 */
const FLEX_MIN = 220;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

export default function Index({ terminals }: { terminals: TerminalRow[] }) {
    const can = useCan();
    const manage = can('terminals.manage');

    const addTerminal = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden strokeWidth={1.5} />
                Register terminal
            </Link>
        </Button>
    ) : null;

    return (
        <AppLayout>
            <PageHeader
                title="Terminals"
                description="The biometric devices that capture punches. Each one has a device number, and every file it exports carries that number on every line."
                actions={addTerminal}
            />

            {terminals.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No terminals yet"
                        description="Register the device before importing anything from it: the number you give it here has to match the number the device stamps into its own export, or the file is refused."
                        action={addTerminal ?? undefined}
                    />
                </Card>
            ) : (
                // `min-w-min` plus a clipped, nothing-to-clip container, so the
                // SHELL scrolls sideways and the sticky head keeps its
                // scrollport — see employees/index.tsx for the full note.
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>Devices</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {terminals.length} {terminals.length === 1 ? 'terminal' : 'terminals'}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Terminals, with how many people each can identify and how many punches it has captured
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.code }}>Device</TableHead>
                                <TableHead style={{ width: COLUMNS.name }}>Name</TableHead>
                                <TableHead>Where</TableHead>
                                <TableHead style={{ width: COLUMNS.imported }}>Last import</TableHead>
                                <TableHead style={{ width: COLUMNS.enrolled }} numeric>
                                    Enrolled
                                </TableHead>
                                <TableHead style={{ width: COLUMNS.punches }} numeric>
                                    Punches
                                </TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {terminals.map((terminal) => (
                                <TableRow key={terminal.id} className="group/row">
                                    <TableCell className="tabular-nums">{terminal.code}</TableCell>
                                    <TableCell className="max-w-0">
                                        <span className="flex min-w-0 flex-col">
                                            <span className="truncate">{terminal.name}</span>
                                            {/*
                                              Retired devices keep their rows and
                                              their punches — `active` is a switch,
                                              not a delete — so the row has to say
                                              which it is without a second column.
                                            */}
                                            {!terminal.active && (
                                                <span className="text-muted-foreground text-xs">Not in service</span>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="max-w-0">
                                        <span className="block truncate">
                                            {terminal.workgroup?.name ?? (
                                                <span className="text-muted-foreground">Agency-wide</span>
                                            )}
                                        </span>
                                    </TableCell>
                                    {/*
                                      A device nobody is enrolled on cannot
                                      attribute a single punch it captures — they
                                      arrive and sit unresolved. Zero is the most
                                      important number on this row, so it is
                                      stated rather than dashed.
                                    */}
                                    {/*
                                      The question a timekeeper actually opens
                                      this page with. A device nobody has
                                      imported from in three weeks is the one
                                      whose absence from payroll nobody has
                                      noticed yet.
                                    */}
                                    <TableCell className="tabular-nums">
                                        {terminal.last_import_at ? (
                                            formatDay(terminal.last_import_at.slice(0, 10))
                                        ) : (
                                            <span className="text-muted-foreground">Never</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {terminal.enrolled_count === 0 ? (
                                            <span className="text-muted-foreground">Nobody</span>
                                        ) : (
                                            terminal.enrolled_count
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {terminal.timelogs_count.toLocaleString()}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowMenu terminal={terminal} manage={manage} />
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
 * Import always (it is the point of the screen); Edit always; Remove only
 * where it can succeed.
 *
 * Every foreign key into `terminals` RESTRICTs, so a device that has captured
 * anything is refused by the database. The item is absent rather than
 * offered-and-broken — and the Punches count sitting two cells away is what
 * explains the absence. That is the primary defence, not the only one:
 * `TerminalController::destroy` translates the 23001 as well, because this
 * page can be stale while an import is running.
 */
function RowMenu({ terminal, manage }: { terminal: TerminalRow; manage: boolean }) {
    const [importing, setImporting] = useState(false);

    if (!manage) {
        return null;
    }

    const removable = terminal.timelogs_count === 0;

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${terminal.name}`}>
                        <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-[252px]">
                    <DropdownMenuItem onSelect={() => setImporting(true)}>
                        <UploadIcon aria-hidden strokeWidth={1.5} />
                        Import timelogs…
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link href={edit(terminal)} className="w-full">
                            <PencilIcon aria-hidden strokeWidth={1.5} />
                            Edit terminal
                        </Link>
                    </DropdownMenuItem>
                    {removable && (
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                                    <Trash2Icon aria-hidden strokeWidth={1.5} />
                                    Remove terminal
                                </DropdownMenuItem>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Remove {terminal.name}?</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        It has captured no punches, so nothing is lost. You can register it again with
                                        the same device number.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Keep terminal</AlertDialogCancel>
                                    <AlertDialogAction
                                        variant="destructive"
                                        onClick={() => router.delete(destroy.url(terminal))}
                                    >
                                        Remove terminal
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <ImportDialog terminal={terminal} open={importing} onOpenChange={setImporting} />
        </>
    );
}

/**
 * Upload an attlog export.
 *
 * The dialog's job is to make the two things that decide the outcome visible
 * **before** submitting, because both fail the whole file rather than a row.
 *
 * The device number is stated in the description, not left implicit: a file
 * recorded by another device is refused whole (decision 44), and an operator
 * holding several exports needs to know which one this terminal will take.
 *
 * The layout is a choice and never a guess, for the reason decision 44 gives:
 * a line like `1 <time> 0 1 0` is valid under both readings and means
 * different things, so any sniffing heuristic is a coin toss on exactly the
 * files where it matters. Both options therefore show their **column order**
 * rather than a name, so the operator matches it against the file in front of
 * them; and the option that includes the device number says what it buys,
 * since it is the one that lets the file be checked against this terminal at
 * all.
 */
function ImportDialog({
    terminal,
    open,
    onOpenChange,
}: {
    terminal: TerminalRow;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [layout, setLayout] = useState('standard');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[540px]">
                <DialogHeader>
                    <DialogTitle>Import timelogs</DialogTitle>
                    <DialogDescription>
                        The file {terminal.name} exported. Every line must carry device number{' '}
                        <span className="text-foreground tabular-nums">{terminal.code}</span> — a file recorded by
                        another device, or one naming several, is refused whole and nothing is imported.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...importTimelogs.form(terminal)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    disableWhileProcessing
                >
                    {({ errors, processing }) => (
                        <>
                            <Field label="File" htmlFor="file" error={errors.file}>
                                {({ id, invalid, describedBy }) => (
                                    <Input
                                        id={id}
                                        name="file"
                                        type="file"
                                        accept=".dat,.txt,.csv,text/plain,text/csv"
                                        aria-invalid={invalid}
                                        aria-describedby={describedBy}
                                    />
                                )}
                            </Field>

                            <Field
                                className="mt-6"
                                label="Column order"
                                error={errors.layout}
                                hint="Open the file and match the first line against these."
                            >
                                {({ describedBy }) => (
                                    <RadioGroup
                                        name="layout"
                                        value={layout}
                                        onValueChange={setLayout}
                                        aria-describedby={describedBy}
                                        className="gap-3"
                                    >
                                        <label className="flex items-start gap-3" htmlFor="layout-standard">
                                            <RadioGroupItem id="layout-standard" value="standard" className="mt-1" />
                                            <span className="flex flex-col gap-0.5">
                                                <span className="font-mono text-sm">uid · time · state · mode</span>
                                                <span className="text-muted-foreground text-xs">
                                                    No device number in the file, so it cannot be checked against this
                                                    terminal.
                                                </span>
                                            </span>
                                        </label>
                                        <label className="flex items-start gap-3" htmlFor="layout-device">
                                            <RadioGroupItem id="layout-device" value="device" className="mt-1" />
                                            <span className="flex flex-col gap-0.5">
                                                <span className="font-mono text-sm">
                                                    uid · time · <span className="text-foreground">device</span> · state
                                                    · mode
                                                </span>
                                                <span className="text-muted-foreground text-xs">
                                                    Checked against device number {terminal.code} before anything is
                                                    written.
                                                </span>
                                            </span>
                                        </label>
                                    </RadioGroup>
                                )}
                            </Field>

                            <DialogFooter className="pt-8">
                                <Button variant="ghost" type="button" onClick={() => onOpenChange(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    <UploadIcon aria-hidden strokeWidth={1.5} />
                                    Import
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

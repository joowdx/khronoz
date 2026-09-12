import { Form, Link, router } from '@inertiajs/react';
import { MoreHorizontalIcon, PencilIcon, PlusIcon, Trash2Icon, UploadIcon, UsersRoundIcon } from 'lucide-react';
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
import { index as enrollments } from '@/routes/terminals/enrollments';
import { index as timelogs } from '@/routes/timelogs';
import { store as importTimelogs } from '@/routes/terminals/syncs';
import type { Terminal } from '@/types';

interface TerminalRow extends Terminal {
    enrolled_count: number;
    timelogs_count: number;
    enrollments_count: number;
    syncs_count: number;
    last_import_at?: string | null;
}

const COLUMNS = {
    code: 90,
    name: 300,
    imported: 150,
    enrolled: 110,
    punches: 120,
    actions: 68,
} as const;

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
                                    <TableCell className="tabular-nums">
                                        {terminal.last_import_at ? (
                                            formatDay(terminal.last_import_at.slice(0, 10))
                                        ) : (
                                            <span className="text-muted-foreground">Never</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <Link
                                            href={enrollments(terminal)}
                                            className="hover:text-foreground underline-offset-4 hover:underline"
                                        >
                                            {terminal.enrolled_count === 0 ? (
                                                <span className="text-muted-foreground">Nobody</span>
                                            ) : (
                                                terminal.enrolled_count
                                            )}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <Link
                                            href={timelogs.url({ query: { terminal: terminal.id } })}
                                            className="hover:text-foreground underline-offset-4 hover:underline"
                                        >
                                            {terminal.timelogs_count.toLocaleString()}
                                        </Link>
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

function RowMenu({ terminal, manage }: { terminal: TerminalRow; manage: boolean }) {
    const [importing, setImporting] = useState(false);

    if (!manage) {
        return null;
    }

    const removable = terminal.timelogs_count === 0 && terminal.enrollments_count === 0 && terminal.syncs_count === 0;

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
                        <Link href={enrollments(terminal)} className="w-full">
                            <UsersRoundIcon aria-hidden strokeWidth={1.5} />
                            Enrolled people
                        </Link>
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

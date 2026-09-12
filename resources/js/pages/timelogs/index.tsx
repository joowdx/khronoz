import { Form, Link, router } from '@inertiajs/react';
import { BanIcon, MoreHorizontalIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Combobox } from '@/components/combobox';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
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
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDay } from '@/lib/dates';
import { index, voidMethod as voidTimelog } from '@/routes/timelogs';
import type { Terminal, Timelog } from '@/types';

interface Filters {
    terminal: string;
    unresolved: boolean;
    voided: boolean;
    uid: string;
    from: string;
    to: string;
}

interface Pagination {
    from: number | null;
    to: number | null;
    total: number;
    previous: string | null;
    next: string | null;
}

const PARTIAL = ['timelogs', 'pagination', 'filters'];

const COLUMNS = {
    time: 170,
    device: 100,
    uid: 110,
    state: 150,
    mode: 120,
    actions: 68,
} as const;

const FLEX_MIN = 240;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

function query(filters: Filters, page?: string): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.terminal !== '') {
        params.terminal = filters.terminal;
    }

    if (filters.unresolved) {
        params.unresolved = '1';
    }

    if (filters.voided) {
        params.voided = '1';
    }

    if (filters.uid !== '') {
        params.uid = filters.uid;
    }

    if (filters.from !== '') {
        params.from = filters.from;
    }

    if (filters.to !== '') {
        params.to = filters.to;
    }

    if (page) {
        params.page = page;
    }

    return params;
}

export default function Index({
    timelogs,
    pagination,
    filters,
    terminals,
}: {
    timelogs: Timelog[];
    pagination: Pagination;
    filters: Filters;
    terminals: Terminal[];
}) {
    const can = useCan();
    const manage = can('terminals.manage');
    const [uid, setUid] = useState(filters.uid);

    useEffect(() => {
        if (uid === filters.uid) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(index.url(), query({ ...filters, uid }), {
                only: PARTIAL,
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 250);

        return () => clearTimeout(timer);
    }, [uid]); // eslint-disable-line react-hooks/exhaustive-deps

    function go(next: Partial<Filters>) {
        router.get(index.url(), query({ ...filters, uid, ...next }), {
            only: PARTIAL,
            preserveState: true,
            preserveScroll: true,
        });
    }

    const filtered =
        filters.terminal !== '' || filters.unresolved || filters.voided || filters.uid !== '' ||
        filters.from !== '' || filters.to !== '';

    return (
        <AppLayout>
            <PageHeader
                title="Timelogs"
                description="What the devices recorded. Nothing here is ever edited or deleted — a bad punch is voided and stays."
            />

            <div className="mb-6 flex flex-wrap items-end gap-3">
                <Field className="w-[240px]" label="Device" htmlFor="terminal">
                    {({ id }) => (
                        <Combobox
                            id={id}
                            value={filters.terminal === '' ? null : filters.terminal}
                            onValueChange={(value) => go({ terminal: value ?? '' })}
                            placeholder="Every device"
                            searchPlaceholder="Search devices"
                            empty="No device by that name."
                            clearLabel="Every device"
                            options={terminals.map((terminal) => ({
                                value: terminal.id,
                                label: terminal.name,
                                keywords: [terminal.code],
                                trigger: terminal.name,
                                render: (
                                    <span className="flex min-w-0 items-baseline gap-2">
                                        <span className="truncate">{terminal.name}</span>
                                        <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                                            {terminal.code}
                                        </span>
                                    </span>
                                ),
                            }))}
                        />
                    )}
                </Field>

                <Field className="w-[150px]" label="Device user" htmlFor="uid">
                    {({ id }) => (
                        <Input
                            id={id}
                            value={uid}
                            onChange={(event) => setUid(event.target.value)}
                            placeholder="Any"
                            className="tabular-nums"
                        />
                    )}
                </Field>

                <Field className="w-[160px]" label="From" htmlFor="from">
                    {({ id }) => (
                        <Input
                            id={id}
                            type="date"
                            value={filters.from}
                            onChange={(event) => go({ from: event.target.value })}
                        />
                    )}
                </Field>
                <Field className="w-[160px]" label="To" htmlFor="to">
                    {({ id }) => (
                        <Input
                            id={id}
                            type="date"
                            value={filters.to}
                            onChange={(event) => go({ to: event.target.value })}
                        />
                    )}
                </Field>

                <Field label="Only show" htmlFor="only">
                    {({ id }) => (
                        <ToggleGroup
                            id={id}
                            type="multiple"
                            value={[
                                ...(filters.unresolved ? ['unresolved'] : []),
                                ...(filters.voided ? ['voided'] : []),
                            ]}
                            onValueChange={(value) =>
                                go({ unresolved: value.includes('unresolved'), voided: value.includes('voided') })
                            }
                            variant="outline"
                        >
                            <ToggleGroupItem value="unresolved">Unattributed</ToggleGroupItem>
                            <ToggleGroupItem value="voided">Voided</ToggleGroupItem>
                        </ToggleGroup>
                    )}
                </Field>

                {filtered && (
                    <Button
                        variant="ghost"
                        onClick={() => {
                            setUid('');
                            router.get(index.url(), {}, { only: PARTIAL, preserveState: true, preserveScroll: true });
                        }}
                    >
                        Clear filters
                    </Button>
                )}
            </div>

            {timelogs.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title={filtered ? 'Nothing matches those filters' : 'No timelogs yet'}
                        description={
                            filtered
                                ? 'Widen the dates, or clear the filters to see everything the devices have recorded.'
                                : 'Import a device’s attlog export from the Terminals screen and its punches will appear here.'
                        }
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>Recorded</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {pagination.from}–{pagination.to} of {pagination.total.toLocaleString()}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Timelogs, most recent first
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.time }}>When</TableHead>
                                <TableHead style={{ width: COLUMNS.device }}>Device</TableHead>
                                <TableHead style={{ width: COLUMNS.uid }}>Device user</TableHead>
                                <TableHead>Person</TableHead>
                                <TableHead style={{ width: COLUMNS.state }}>What</TableHead>
                                <TableHead style={{ width: COLUMNS.mode }}>How</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {timelogs.map((timelog) => (
                                <TableRow key={timelog.id}>
                                    <TableCell className="tabular-nums">
                                        <span className="flex flex-col">
                                            <span>{formatDay(timelog.time.slice(0, 10))}</span>
                                            <span className="text-muted-foreground text-xs">
                                                {timelog.time.slice(11, 16)}
                                            </span>
                                        </span>
                                    </TableCell>
                                    <TableCell className="tabular-nums">{timelog.terminal?.code ?? '—'}</TableCell>
                                    <TableCell className="tabular-nums">{timelog.uid}</TableCell>
                                    <TableCell className="max-w-0">
                                        <span className="flex min-w-0 flex-col">
                                            <span className="truncate">
                                                {timelog.employee?.name ?? (
                                                    <span className="text-muted-foreground">Unattributed</span>
                                                )}
                                            </span>
                                            {timelog.voided_at && (
                                                <span className="text-muted-foreground truncate text-xs">
                                                    Voided
                                                    {timelog.voider ? ` by ${timelog.voider.name}` : ''} —{' '}
                                                    {timelog.reason}
                                                </span>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell>{timelog.state.label}</TableCell>
                                    <TableCell className="text-muted-foreground">{timelog.mode.label}</TableCell>
                                    <TableCell className="text-right">
                                        {manage && timelog.voided_at === null && <VoidMenu timelog={timelog} />}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            {(pagination.previous || pagination.next) && (
                <div className="mt-6 flex justify-end gap-2">
                    {pagination.previous && (
                        <Button variant="outline" asChild>
                            <Link href={pagination.previous} only={PARTIAL} preserveScroll>
                                Previous
                            </Link>
                        </Button>
                    )}
                    {pagination.next && (
                        <Button variant="outline" asChild>
                            <Link href={pagination.next} only={PARTIAL} preserveScroll>
                                Next
                            </Link>
                        </Button>
                    )}
                </div>
            )}
        </AppLayout>
    );
}

function VoidMenu({ timelog }: { timelog: Timelog }) {
    const [voiding, setVoiding] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`Actions for the punch at ${timelog.time}`}
                    >
                        <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-[252px]">
                    <DropdownMenuItem variant="destructive" onSelect={() => setVoiding(true)}>
                        <BanIcon aria-hidden strokeWidth={1.5} />
                        Void punch…
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={voiding} onOpenChange={setVoiding}>
                <DialogContent className="sm:max-w-[480px]">
                    <DialogHeader>
                        <DialogTitle>Void this punch</DialogTitle>
                        <DialogDescription>
                            It stays on the record and stays visible — voiding marks it as not counting, and says who
                            decided that and why. Nothing here is ever deleted.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...voidTimelog.form(timelog)}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setVoiding(false)}
                        disableWhileProcessing
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field label="Reason" htmlFor="reason" error={errors.reason}>
                                    {({ id, invalid, describedBy }) => (
                                        <Input
                                            id={id}
                                            name="reason"
                                            autoFocus
                                            placeholder="Duplicate scan"
                                            maxLength={255}
                                            aria-invalid={invalid}
                                            aria-describedby={describedBy}
                                        />
                                    )}
                                </Field>
                                <DialogFooter className="pt-8">
                                    <Button variant="ghost" type="button" onClick={() => setVoiding(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" variant="destructive" disabled={processing}>
                                        Void punch
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

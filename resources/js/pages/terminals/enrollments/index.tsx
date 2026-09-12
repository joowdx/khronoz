import { Form, Link, router } from '@inertiajs/react';
import { CircleSlashIcon, MoreHorizontalIcon, PlusIcon } from 'lucide-react';
import { useState } from 'react';
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
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDay, manilaToday } from '@/lib/dates';
import { index as terminalsIndex } from '@/routes/terminals';
import { store, update } from '@/routes/terminals/enrollments';
import type { Choice, Employee, Enrollment, Terminal } from '@/types';

const COLUMNS = {
    uid: 130,
    privilege: 130,
    from: 140,
    until: 140,
    actions: 68,
} as const;

const FLEX_MIN = 260;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

export default function Index({
    terminal,
    enrollments,
    employees,
    privileges,
}: {
    terminal: Terminal;
    enrollments: Enrollment[];
    employees: Employee[];
    privileges: Choice[];
}) {
    const can = useCan();
    const manage = can('terminals.manage');
    const [enrolling, setEnrolling] = useState(false);

    const current = enrollments.filter((enrollment) => enrollment.current);

    const enrol = manage ? (
        <Button onClick={() => setEnrolling(true)}>
            <PlusIcon aria-hidden strokeWidth={1.5} />
            Enrol someone
        </Button>
    ) : null;

    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Terminals', href: terminalsIndex().url }}
                title={terminal.name}
                description={`Who device ${terminal.code} can identify. Without an enrollment, its punches arrive unattributed.`}
                actions={enrol}
            />

            {enrollments.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="Nobody is enrolled"
                        description="Every punch this device captures will arrive unattributed until somebody is enrolled on it. Enrol them under the device user id the device itself assigned — not their employee number."
                        action={enrol ?? undefined}
                    />
                </Card>
            ) : (
                <Card className="min-w-min overflow-visible">
                    <CardHeader>
                        <CardTitle>Enrolled</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {current.length} now · {enrollments.length} in all
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Enrollments on this terminal, current ones first
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.uid }}>Device user</TableHead>
                                <TableHead>Person</TableHead>
                                <TableHead style={{ width: COLUMNS.privilege }}>Privilege</TableHead>
                                <TableHead style={{ width: COLUMNS.from }}>From</TableHead>
                                <TableHead style={{ width: COLUMNS.until }}>Until</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {enrollments.map((enrollment) => (
                                <TableRow key={enrollment.id}>
                                    <TableCell className="tabular-nums">{enrollment.uid}</TableCell>
                                    <TableCell className="max-w-0">
                                        <span className="block truncate">
                                            {enrollment.employee?.name ?? (
                                                <span className="text-muted-foreground">Removed</span>
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell>{enrollment.privilege.label}</TableCell>
                                    <TableCell className="tabular-nums">{formatDay(enrollment.starts)}</TableCell>
                                    <TableCell className="tabular-nums">
                                        {enrollment.ends ? (
                                            formatDay(enrollment.ends)
                                        ) : (
                                            <span className="text-muted-foreground">Present</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {manage && enrollment.current && (
                                            <EndMenu terminal={terminal} enrollment={enrollment} />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            <EnrolDialog
                terminal={terminal}
                employees={employees}
                privileges={privileges}
                open={enrolling}
                onOpenChange={setEnrolling}
            />
        </AppLayout>
    );
}

function EndMenu({ terminal, enrollment }: { terminal: Terminal; enrollment: Enrollment }) {
    const [ending, setEnding] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon-sm" aria-label={`Actions for device user ${enrollment.uid}`}>
                        <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-[252px]">
                    <DropdownMenuItem onSelect={() => setEnding(true)}>
                        <CircleSlashIcon aria-hidden strokeWidth={1.5} />
                        End enrollment…
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={ending} onOpenChange={setEnding}>
                <DialogContent className="sm:max-w-[460px]">
                    <DialogHeader>
                        <DialogTitle>End this enrollment</DialogTitle>
                        <DialogDescription>
                            {enrollment.employee?.name ?? 'This person'} stops being device user{' '}
                            <span className="text-foreground tabular-nums">{enrollment.uid}</span> on {terminal.name}.
                            Punches already attributed to them stay; the device user id becomes free to reissue from the
                            day after.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...update.form([terminal, enrollment])}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setEnding(false)}
                        disableWhileProcessing
                    >
                        {({ errors, processing }) => (
                            <>
                                <input type="hidden" name="expects" value={enrollment.ends ?? ''} />

                                <Field label="Last day" htmlFor="ends" error={errors.ends}>
                                    {({ id, invalid, describedBy }) => (
                                        <Input
                                            id={id}
                                            name="ends"
                                            type="date"
                                            defaultValue={manilaToday()}
                                            min={enrollment.starts}
                                            aria-invalid={invalid}
                                            aria-describedby={describedBy}
                                        />
                                    )}
                                </Field>
                                <DialogFooter className="pt-8">
                                    <Button variant="ghost" type="button" onClick={() => setEnding(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        End enrollment
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

function EnrolDialog({
    terminal,
    employees,
    privileges,
    open,
    onOpenChange,
}: {
    terminal: Terminal;
    employees: Employee[];
    privileges: Choice[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [employee, setEmployee] = useState<string | null>(null);
    const [privilege, setPrivilege] = useState('user');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[520px]">
                <DialogHeader>
                    <DialogTitle>Enrol on {terminal.name}</DialogTitle>
                    <DialogDescription>
                        Under the device user id this device assigned them — read it off the device, not off their
                        employee record.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...store.form(terminal)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    disableWhileProcessing
                >
                    {({ errors, processing }) => (
                        <>
                            <Field label="Person" htmlFor="employee_id" error={errors.employee_id}>
                                {({ id, invalid, describedBy }) => (
                                    <Combobox
                                        id={id}
                                        name="employee_id"
                                        value={employee}
                                        onValueChange={setEmployee}
                                        invalid={invalid}
                                        describedBy={describedBy}
                                        placeholder="Choose an employee"
                                        searchPlaceholder="Search employees"
                                        empty="Nobody by that name."
                                        options={employees.map((option) => ({
                                            value: option.id,
                                            label: option.name,
                                            keywords: [option.number, option.position ?? ''],
                                            trigger: option.name,
                                            render: (
                                                <span className="flex min-w-0 items-baseline gap-2">
                                                    <span className="truncate">{option.name}</span>
                                                    <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                                                        {option.number}
                                                    </span>
                                                </span>
                                            ),
                                        }))}
                                    />
                                )}
                            </Field>

                            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr]">
                                <Field
                                    label="Device user id"
                                    htmlFor="uid"
                                    error={errors.uid}
                                    hint="Exactly as the device shows it, leading zeros and all."
                                >
                                    {({ id, invalid, describedBy }) => (
                                        <Input
                                            id={id}
                                            name="uid"
                                            placeholder="007"
                                            maxLength={255}
                                            className="tabular-nums"
                                            aria-invalid={invalid}
                                            aria-describedby={describedBy}
                                        />
                                    )}
                                </Field>
                                <Field label="Privilege" htmlFor="privilege" error={errors.privilege}>
                                    {({ id, invalid, describedBy }) => (
                                        <Combobox
                                            id={id}
                                            name="privilege"
                                            value={privilege}
                                            onValueChange={(next) => setPrivilege(next ?? 'user')}
                                            invalid={invalid}
                                            describedBy={describedBy}
                                            placeholder="User"
                                            searchPlaceholder="Search"
                                            empty="No such privilege."
                                            options={privileges.map((option) => ({ ...option, trigger: option.label }))}
                                        />
                                    )}
                                </Field>
                            </div>

                            <Field
                                className="mt-6"
                                label="From"
                                htmlFor="starts"
                                error={errors.starts}
                                hint="Punches before this date will not resolve to them."
                            >
                                {({ id, invalid, describedBy }) => (
                                    <Input
                                        id={id}
                                        name="starts"
                                        type="date"
                                        defaultValue={manilaToday()}
                                        aria-invalid={invalid}
                                        aria-describedby={describedBy}
                                    />
                                )}
                            </Field>

                            <DialogFooter className="pt-8">
                                <Button variant="ghost" type="button" onClick={() => onOpenChange(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Enrol
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

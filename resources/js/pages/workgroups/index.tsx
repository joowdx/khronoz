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
import { flattenWorkgroups } from '@/lib/workgroups';
import { cn } from '@/lib/utils';
import { index as employeesIndex } from '@/routes/employees';
import { create, destroy, edit, update } from '@/routes/workgroups';
import type { Employee, Workgroup } from '@/types';

interface WorkgroupRow extends Workgroup {
    people_count: number;
    deployments_count: number;
}

function Guides({ guides }: { guides: boolean[] }) {
    return guides.map((draw, slot) => (
        <span
            key={slot}
            aria-hidden
            className={cn('h-full w-[22px] shrink-0', draw && 'border-input border-l')}
        />
    ));
}

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

const COLUMNS = {
    workgroup: 480,
    kind: 150,
    people: 110,
    actions: 68,
} as const;

const FLEX_MIN = 252;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

export default function Index({ workgroups, employees }: { workgroups: WorkgroupRow[]; employees: Employee[] }) {
    const can = useCan();
    const manage = can('organization.manage');
    const tree = flattenWorkgroups(workgroups);

    function setHead(workgroup: WorkgroupRow, headId: string | null): void {
        router.put(
            update.url(workgroup),
            {
                parent_id: workgroup.parent_id ?? '',
                kind: workgroup.kind ?? '',
                code: workgroup.code,
                name: workgroup.name,
                head_id: headId ?? '',
            },
            { preserveScroll: true },
        );
    }

    const addWorkgroup = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden />
                Add workgroup
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
                title="Workgroups"
                description="How your agency is organised. Each workgroup sits under one parent, and employees are deployed into them."
                actions={addWorkgroup}
            />

            {workgroups.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No workgroups yet"
                        description="A workgroup is a box on your org chart: a department, a division, a section, an office. Add the top one first, then add what sits under it."
                        action={addWorkgroup ?? undefined}
                    />
                </Card>
            ) : (
                <Card
                    className="min-w-min overflow-visible"
                >
                    <CardHeader>
                        <CardTitle>Structure</CardTitle>
                        <CardDescription className="ml-auto tabular-nums">
                            {workgroups.length} {workgroups.length === 1 ? 'workgroup' : 'workgroups'}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">
                            Workgroups, indented under the workgroup each one sits in
                        </TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead style={{ width: COLUMNS.workgroup }}>Workgroup</TableHead>
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
                            {tree.map(({ workgroup, depth, last, children, guides }) => (
                                <TableRow key={workgroup.id} className="group/row">
                                    <TableCell className="max-w-0 py-0">
                                        <span className="flex h-11 items-center">
                                            <Guides guides={guides} />
                                            {depth > 0 && <Elbow last={last} />}
                                            <span
                                                className={cn(
                                                    'min-w-16 truncate',
                                                    depth === 0 ? 'font-medium' : 'font-normal',
                                                )}
                                            >
                                                {workgroup.name}
                                            </span>
                                            <span className="text-muted-foreground ml-2.5 truncate text-xs">
                                                {workgroup.code}
                                            </span>
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground max-w-0 truncate text-[13px]">
                                        {workgroup.kind ?? '—'}
                                    </TableCell>
                                    <TableCell numeric>
                                        {workgroup.people_count === 0 ? (
                                            <span className="text-muted-foreground">—</span>
                                        ) : (
                                            <Link
                                                href={employeesIndex({ query: { workgroup: workgroup.id } })}
                                                className="hover:text-acc-text underline-offset-2 hover:underline"
                                                aria-label={`${workgroup.people_count} in ${workgroup.name} and below`}
                                            >
                                                {workgroup.people_count}
                                            </Link>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {manage ? (
                                            <Combobox
                                                variant="inline"
                                                label={`Head of ${workgroup.name}`}
                                                value={workgroup.head_id}
                                                onValueChange={(value) => setHead(workgroup, value)}
                                                options={heads}
                                                placeholder="Choose a head"
                                                searchPlaceholder="Search employees"
                                                empty="Nobody by that name."
                                                clearLabel="No head"
                                            />
                                        ) : (
                                            (workgroup.head?.name ?? <span className="text-muted-foreground">—</span>)
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <RowMenu workgroup={workgroup} childCount={children} manage={manage} />
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

function RowMenu({ workgroup, childCount, manage }: { workgroup: WorkgroupRow; childCount: number; manage: boolean }) {
    if (!manage) {
        return null;
    }

    const removable = workgroup.deployments_count === 0 && childCount === 0;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${workgroup.name}`}>
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(workgroup)} className="w-full">
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit workgroup
                    </Link>
                </DropdownMenuItem>
                {removable && (
                    <AlertDialog>
                        <AlertDialogTrigger asChild>
                            <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                                <Trash2Icon aria-hidden strokeWidth={1.5} />
                                Remove workgroup
                            </DropdownMenuItem>
                        </AlertDialogTrigger>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Remove {workgroup.name}?</AlertDialogTitle>
                                <AlertDialogDescription>
                                    Nobody has ever been deployed to it, so nothing is lost. You can add it again with
                                    the same code.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Keep workgroup</AlertDialogCancel>
                                <AlertDialogAction
                                    variant="destructive"
                                    onClick={() => router.delete(destroy.url(workgroup))}
                                >
                                    Remove workgroup
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

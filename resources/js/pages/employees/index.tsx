import { Link, router } from '@inertiajs/react';
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    MoreHorizontalIcon,
    PencilIcon,
    PlusIcon,
    SearchIcon,
    UserMinusIcon,
} from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
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
import { Avatar, AvatarFallback, avatarTint, initials } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardFooter, CardHeader } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { flattenWorkgroups } from '@/lib/workgroups';
import { create, destroy, edit, index, show } from '@/routes/employees';
import type { Employee, Workgroup } from '@/types';

interface Filters {
    search: string;
    workgroup: string;
    tag: string;
    exempt: boolean;
}

interface Pagination {
    from: number | null;
    to: number | null;
    total: number;
    previous: string | null;
    next: string | null;
}

const PARTIAL = ['employees', 'pagination', 'filters'];

const COLUMNS = {
    workgroup: 270,
    position: 260,
    tags: 200,
    actions: 68,
} as const;

const FLEX_MIN = 242;

const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

function query(filters: Filters, page?: string): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.search !== '') {
        params.search = filters.search;
    }

    if (filters.workgroup !== '') {
        params.workgroup = filters.workgroup;
    }

    if (filters.tag !== '') {
        params.tag = filters.tag;
    }

    if (filters.exempt) {
        params.exempt = '1';
    }

    if (page) {
        params.page = page;
    }

    return params;
}

function activeFilters(filters: Filters): string[] {
    return [
        filters.search !== '' ? `the search for “${filters.search}”` : null,
        filters.workgroup !== '' ? 'the workgroup filter' : null,
        filters.tag !== '' ? 'the tag filter' : null,
        filters.exempt ? 'Exempt only' : null,
    ].filter((phrase): phrase is string => phrase !== null);
}

function sentenceList(parts: string[]): string {
    if (parts.length <= 1) {
        return parts.join('');
    }

    return `${parts.slice(0, -1).join(', ')} and ${parts.at(-1)}`;
}

function Person({ employee }: { employee: Employee }) {
    return (
        <span className="flex min-w-0 items-center gap-2.5">
            <Avatar aria-hidden>
                <AvatarFallback tint={avatarTint(employee.name)}>{initials(employee.name)}</AvatarFallback>
            </Avatar>
            <span className="min-w-0">
                <span className="flex items-center gap-2.5">
                    <Link
                        href={show(employee)}
                        className="hover:text-acc-text truncate text-sm leading-[18px] font-medium transition-[color]"
                    >
                        {employee.name}
                    </Link>
                    {employee.exempt && <Badge variant="secondary">Exempt</Badge>}
                </span>
                <span className="text-muted-foreground block truncate text-xs leading-4 tabular-nums">
                    {employee.number}
                </span>
            </span>
        </span>
    );
}

function Tags({ tags }: { tags: string[] }) {
    if (tags.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    const shown = tags.slice(0, 2);
    const rest = tags.slice(2);

    return (
        <span className="flex items-center gap-1.5">
            {shown.map((tag) => (
                <Badge key={tag} variant="secondary">
                    {tag}
                </Badge>
            ))}
            {rest.length > 0 && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Badge variant="outline" className="tabular-nums">
                            +{rest.length}
                        </Badge>
                    </TooltipTrigger>
                    <TooltipContent>{rest.join(', ')}</TooltipContent>
                </Tooltip>
            )}
        </span>
    );
}

export default function Index({
    employees,
    pagination,
    filters,
    workgroups,
    tags,
}: {
    employees: Employee[];
    pagination: Pagination;
    filters: Filters;
    workgroups: Workgroup[];
    tags: string[];
}) {
    const can = useCan();
    const manage = can('organization.manage');
    const [search, setSearch] = useState(filters.search);

    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(index.url(), query({ ...filters, search }), {
                only: PARTIAL,
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 250);

        return () => window.clearTimeout(timer);
    }, [search, filters]);

    function go(next: Partial<Filters>) {
        router.get(index.url(), query({ ...filters, search, ...next }), {
            only: PARTIAL,
            preserveState: true,
            preserveScroll: true,
        });
    }

    const tree = flattenWorkgroups(workgroups);
    const addEmployee = manage ? (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden />
                Add employee
            </Link>
        </Button>
    ) : null;

    const applied = activeFilters(filters);

    return (
        <AppLayout>
            <PageHeader
                title="Employees"
                description="The people in your agency, and where each of them is deployed"
                actions={addEmployee}
            />

            {pagination.total === 0 && applied.length === 0 ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No employees yet"
                        description="An employee is a person you file a daily time record for. Add them here, then deploy each one to a workgroup so their record knows where they work."
                        action={addEmployee ?? undefined}
                    />
                </Card>
            ) : (
                <Card
                    className="min-w-min overflow-visible"
                >
                    <CardHeader className="min-h-[60px] flex-wrap gap-3">
                        <span className="relative">
                            <SearchIcon
                                aria-hidden
                                strokeWidth={1.5}
                                className="text-muted-foreground pointer-events-none absolute top-1/2 left-[11px] size-4 -translate-y-1/2"
                            />
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                aria-label="Search employees"
                                placeholder="Search name or number"
                                className="w-[260px] pl-[34px]"
                            />
                        </span>

                        <Combobox
                            label="Filter by workgroup"
                            className="w-[220px] font-normal"
                            placeholder="Any workgroup"
                            searchPlaceholder="Search workgroups"
                            empty="No workgroup by that name."
                            clearLabel="Any workgroup"
                            value={filters.workgroup === '' ? null : filters.workgroup}
                            onValueChange={(value) => go({ workgroup: value ?? '' })}
                            options={tree.map(({ workgroup, depth }) => ({
                                value: workgroup.id,
                                label: workgroup.name,
                                keywords: [workgroup.code, workgroup.kind ?? ''],
                                trigger: workgroup.name,
                                render: (
                                    <span className="flex min-w-0 items-center" style={{ paddingLeft: depth * 14 }}>
                                        <span className="truncate">{workgroup.name}</span>
                                        <span className="text-muted-foreground ml-2 shrink-0 text-xs">{workgroup.code}</span>
                                    </span>
                                ),
                            }))}
                        />

                        <Select
                            value={filters.tag === '' ? 'any' : filters.tag}
                            onValueChange={(value) => go({ tag: value === 'any' ? '' : value })}
                        >
                            <SelectTrigger aria-label="Filter by tag" className="justify-start gap-2 font-normal">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent position="popper" align="start" sideOffset={6}>
                                <SelectItem value="any">Any tag</SelectItem>
                                {tags.map((tag) => (
                                    <SelectItem key={tag} value={tag}>
                                        {tag}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <span className="flex items-center gap-2.5 pl-1">
                            <Switch
                                id="exempt-only"
                                checked={filters.exempt}
                                onCheckedChange={(checked) => go({ exempt: checked })}
                            />
                            <Label htmlFor="exempt-only" className="text-[13px] leading-[18px] whitespace-nowrap">
                                Exempt only
                            </Label>
                        </span>

                        <CardDescription className="ml-auto font-medium tabular-nums">
                            {pagination.total} {pagination.total === 1 ? 'employee' : 'employees'}
                        </CardDescription>
                    </CardHeader>

                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">Employees</TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead>Employee</TableHead>
                                <TableHead style={{ width: COLUMNS.workgroup }}>Workgroup</TableHead>
                                <TableHead style={{ width: COLUMNS.position }}>Position</TableHead>
                                <TableHead style={{ width: COLUMNS.tags }}>Tags</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {employees.length === 0 ? (
                                <TableRow className="hover:[&>td]:bg-transparent">
                                    <TableCell colSpan={5} className="h-auto border-b-0 py-2 whitespace-normal">
                                        {applied.length > 0 ? (
                                            <EmptyState
                                                className="py-6"
                                                title="Nobody matches"
                                                description={`No employee is left once ${sentenceList(applied)} ${applied.length === 1 ? 'is' : 'are'} applied. Clear ${applied.length === 1 ? 'it' : 'them'} to see everyone.`}
                                                action={
                                                    <Button
                                                        variant="outline"
                                                        onClick={() => {
                                                            setSearch('');
                                                            go({ search: '', workgroup: '', tag: '', exempt: false });
                                                        }}
                                                    >
                                                        Clear filters
                                                    </Button>
                                                }
                                            />
                                        ) : (
                                            <EmptyState
                                                className="py-6"
                                                title="Nothing on this page"
                                                description="The list is shorter than the page you asked for."
                                                action={
                                                    <Button variant="outline" asChild>
                                                        <Link href={index()}>Back to the first page</Link>
                                                    </Button>
                                                }
                                            />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                employees.map((employee) => (
                                    <TableRow
                                        key={employee.id}
                                        className={cn('group/row')}
                                    >
                                        <TableCell className="h-[52px]">
                                            <Person employee={employee} />
                                        </TableCell>
                                        <TableCell className="max-w-0 truncate">
                                            {employee.current_deployment?.workgroup ? (
                                                employee.current_deployment.workgroup.name
                                            ) : (
                                                <span className="text-muted-foreground">Not deployed</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="max-w-0 truncate">
                                            {employee.position ?? <span className="text-muted-foreground">—</span>}
                                        </TableCell>
                                        <TableCell>
                                            <Tags tags={employee.tags} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <RowMenu employee={employee} manage={manage} />
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>

                    <CardFooter>
                        <span aria-live="polite">
                            {pagination.total === 0
                                ? 'No employees'
                                : pagination.from === null
                                  ? `0 of ${pagination.total}`
                                  : `${pagination.from} to ${pagination.to} of ${pagination.total}`}
                        </span>
                        <span className="flex-1" />
                        <Pager href={pagination.previous} label="Previous page">
                            <ChevronLeftIcon aria-hidden strokeWidth={1.5} />
                        </Pager>
                        <Pager href={pagination.next} label="Next page">
                            <ChevronRightIcon aria-hidden strokeWidth={1.5} />
                        </Pager>
                    </CardFooter>
                </Card>
            )}
        </AppLayout>
    );
}

function Pager({ href, label, children }: { href: string | null; label: string; children: ReactNode }) {
    if (!href) {
        return (
            <Button variant="outline" size="icon-sm" aria-label={label} disabled>
                {children}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="icon-sm" asChild>
            <Link href={href} aria-label={label} only={PARTIAL} preserveScroll>
                {children}
            </Link>
        </Button>
    );
}

function RowMenu({ employee, manage }: { employee: Employee; manage: boolean }) {
    if (!manage) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${employee.name}`}>
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(employee)} className="w-full">
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit record
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                            <UserMinusIcon aria-hidden strokeWidth={1.5} />
                            Remove employee
                        </DropdownMenuItem>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Remove {employee.name}?</AlertDialogTitle>
                            <AlertDialogDescription>
                                They leave the lists and cannot be scheduled again. Their deployments and every daily
                                time record already filed are kept.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep employee</AlertDialogCancel>
                            <AlertDialogAction
                                variant="destructive"
                                onClick={() => router.delete(destroy.url(employee))}
                            >
                                Remove employee
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

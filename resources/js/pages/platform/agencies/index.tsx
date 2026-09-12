import { Form, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeftIcon, ChevronRightIcon, LogInIcon, MoreHorizontalIcon, PencilIcon, SearchIcon } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardFooter, CardHeader } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
    TableSortButton,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { create, edit, enter, index } from '@/routes/platform/agencies';
import type { Agency, SharedProps } from '@/types';

interface AgencyRow extends Agency {
    users_count: number;
}

type SortColumn = 'code' | 'name' | 'users';

interface Filters {
    search: string;
    sort: SortColumn;
    direction: 'asc' | 'desc';
}

interface Pagination {
    from: number | null;
    to: number | null;
    total: number;
    previous: string | null;
    next: string | null;
}

function refine(query: Record<string, string | undefined>, replace = false) {
    router.get(index().url, query, {
        preserveState: true,
        preserveScroll: true,
        replace,
        only: ['agencies', 'filters', 'pagination'],
    });
}

function range({ from, to, total }: Pagination): string {
    if (total === 0) {
        return 'No agencies';
    }

    return from === null ? `0 of ${total}` : `${from} to ${to} of ${total}`;
}

function SortHead({
    column,
    label,
    filters,
    numeric = false,
    className,
}: {
    column: SortColumn;
    label: string;
    filters: Filters;
    numeric?: boolean;
    className?: string;
}) {
    const sorted = filters.sort === column;

    return (
        <TableHead
            numeric={numeric}
            className={className}
            aria-sort={sorted ? (filters.direction === 'asc' ? 'ascending' : 'descending') : 'none'}
        >
            <TableSortButton
                onClick={() =>
                    refine({
                        search: filters.search || undefined,
                        sort: column,
                        direction: sorted && filters.direction === 'asc' ? 'desc' : 'asc',
                    })
                }
            >
                {label}
            </TableSortButton>
        </TableHead>
    );
}

function RowActions({ agency, entered }: { agency: AgencyRow; entered: boolean }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${agency.name}`}>
                    <MoreHorizontalIcon aria-hidden />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <Form {...enter.form(agency)}>
                    {({ processing }) => (
                        <DropdownMenuItem asChild disabled={entered || processing}>
                            <button type="submit" className="w-full" disabled={entered || processing}>
                                <LogInIcon aria-hidden />
                                Enter
                            </button>
                        </DropdownMenuItem>
                    )}
                </Form>
                <DropdownMenuItem asChild>
                    <Link href={edit(agency)} className="w-full">
                        <PencilIcon aria-hidden />
                        Edit agency
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default function Index({
    agencies,
    filters,
    pagination,
}: {
    agencies: AgencyRow[];
    filters: Filters;
    pagination: Pagination;
}) {
    const { agency } = usePage<SharedProps>().props;
    const [search, setSearch] = useState(filters.search);

    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(
            () => refine({ search: search || undefined, sort: filters.sort, direction: filters.direction }, true),
            250,
        );

        return () => clearTimeout(timer);
    }, [search, filters.search, filters.sort, filters.direction]);

    const addAgency = (
        <Button asChild>
            <Link href={create()}>Add agency</Link>
        </Button>
    );

    const searching = filters.search !== '';
    const nothingYet = pagination.total === 0 && !searching;

    return (
        <AppLayout>
            <PageHeader
                title="Agencies"
                description="The agencies khronoz serves. Enter one to work inside its records."
                actions={addAgency}
            />

            {nothingYet ? (
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No agencies yet"
                        description="Every agency keeps its own employees, schedules and daily time records. Add the first one, then enter it to set it up."
                        action={addAgency}
                    />
                </Card>
            ) : (
                <Card
                >
                    <CardHeader className="min-h-[60px]">
                        <div className="relative w-[260px] max-w-full">
                            <SearchIcon
                                aria-hidden
                                strokeWidth={1.5}
                                className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                            />
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Search code or name"
                                aria-label="Search agencies"
                                className="pl-9"
                            />
                        </div>
                        <CardDescription className="ml-auto tabular-nums">
                            {pagination.total} {pagination.total === 1 ? 'agency' : 'agencies'}
                        </CardDescription>
                    </CardHeader>

                    <Table>
                        <TableCaption className="sr-only">Agencies</TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <SortHead column="code" label="Code" filters={filters} className="w-[140px]" />
                                <SortHead column="name" label="Name" filters={filters} />
                                <SortHead
                                    column="users"
                                    label="Users"
                                    filters={filters}
                                    numeric
                                    className="w-[110px]"
                                />
                                <TableHead className="w-16" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {agencies.length === 0 ? (
                                <TableRow className="hover:[&>td]:bg-transparent">
                                    <TableCell colSpan={4} className="h-auto border-b-0 py-2 whitespace-normal">
                                        {searching ? (
                                            <EmptyState
                                                className="py-6"
                                                title={`No agencies match “${filters.search}”`}
                                                description="No code or name contains it. Clear the search to see every agency."
                                                action={
                                                    <Button variant="outline" onClick={() => setSearch('')}>
                                                        Clear search
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
                                agencies.map((row) => {
                                    const entered = agency?.id === row.id;

                                    return (
                                        <TableRow
                                            key={row.id}
                                            data-state={entered ? 'selected' : undefined}
                                        >
                                            <TableCell className="font-medium">{row.code}</TableCell>
                                            <TableCell className="w-full">
                                                <span className="flex items-center gap-2.5">
                                                    <span className="truncate">{row.name}</span>
                                                    {entered && <Badge>You are here</Badge>}
                                                </span>
                                            </TableCell>
                                            <TableCell numeric className="text-muted-foreground">
                                                {row.users_count}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <RowActions agency={row} entered={entered} />
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            )}
                        </TableBody>
                    </Table>

                    <CardFooter>
                        <span aria-live="polite">{range(pagination)}</span>
                        <span className="flex-1" />
                        <Pager href={pagination.previous} label="Previous page">
                            <ChevronLeftIcon aria-hidden />
                        </Pager>
                        <Pager href={pagination.next} label="Next page">
                            <ChevronRightIcon aria-hidden />
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
            <Link href={href} aria-label={label} only={['agencies', 'filters', 'pagination']}>
                {children}
            </Link>
        </Button>
    );
}

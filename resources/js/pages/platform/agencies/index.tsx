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

/**
 * `AgencyResource` plus the one column only this list asks for. The count is
 * a `withCount` aggregate, so it is present here and absent everywhere else
 * (see the resource's docblock) — which is why it lives in a row type rather
 * than on `Agency` itself.
 */
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

/**
 * Search and sort are the query string, not component state: the list a
 * superuser is looking at has to be a link they can send to a colleague. The
 * pager stays a plain `<Link>`, so a page is as shareable as a search.
 *
 * `only` makes it an Inertia partial reload, so the sidebar, the user menu and
 * the flash bag are all left alone — a re-sent flash would fire its toast a
 * second time on every keystroke.
 */
function refine(query: Record<string, string | undefined>, replace = false) {
    router.get(index().url, query, {
        preserveState: true,
        preserveScroll: true,
        replace,
        only: ['agencies', 'filters', 'pagination'],
    });
}

/**
 * The footer's range, which §5.13 makes the table's live status. `from` and
 * `to` are null both when there is nothing at all and when a page number
 * lands past the end of the list, and those two are not the same sentence.
 */
function range({ from, to, total }: Pagination): string {
    if (total === 0) {
        return 'No agencies';
    }

    return from === null ? `0 of ${total}` : `${from} to ${to} of ${total}`;
}

/**
 * A real `<button>` inside the `<th>`, because `aria-sort` is announced but
 * cannot be activated (§5.13, WCAG 2.1.1). Every sortable head declares
 * `aria-sort`, `"none"` included — the artboard's own markup, and what makes a
 * screen reader say "not sorted" on a column that can be.
 */
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

/** Enter, then Edit. Nothing destructive: an agency with users cannot be deleted, and the route does not exist. */
function RowActions({ agency, entered }: { agency: AgencyRow; entered: boolean }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${agency.name}`}>
                    <MoreHorizontalIcon aria-hidden />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                {/* Disabled on the agency you are already in, exactly as the
                    sidebar's switcher disables the current option. */}
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
    // Which agency this superuser has entered is already shared with every
    // page for the sidebar's switcher, so the marker needs no prop of its own.
    const { agency } = usePage<SharedProps>().props;
    const [search, setSearch] = useState(filters.search);

    // Typing is debounced and replaces the history entry, so Back does not
    // walk through every keystroke; the settled query still lands in the URL.
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
                // The screen a fresh install lands on: one panel, one sentence
                // that teaches what an agency is, and the same action the bar
                // offers (§5.20).
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
                    // ui/table.tsx wraps the table in `overflow-x-auto`, and an
                    // overflow ancestor becomes the sticky scrollport — which
                    // silently stops the head pinning under the 56px bar
                    // (components.md). A code, a name and a count have nothing
                    // to scroll sideways, so the container is handed back to
                    // the shell's own scroller. MEASURED: with the primitive's
                    // own overflow the head lands at -75 instead of 56.
                    className="[&_[data-slot=table-container]]:overflow-visible"
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
                        {/* Sticky: the head pins at `top: var(--bar-h)`, under the title bar. */}
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
                                    {/* The head and the filters stay on screen,
                                        because a filter can bring rows back
                                        (§5.13) — so the search that emptied the
                                        table is also the way out of it. */}
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
                                            // A page number past the end of the
                                            // list. Nobody links here, but the
                                            // query string is public, so it says
                                            // what happened rather than blaming a
                                            // search that was never typed.
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
                                            // The selected-row tint (§5.13). Not
                                            // `aria-selected`: that is only valid
                                            // inside a grid, and the word in the
                                            // row is what actually carries the
                                            // meaning.
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

/**
 * A page link while there is a page to go to, and a disabled button when there
 * is not — a link with nowhere to go is worse than an obviously spent control.
 * Real hrefs, so a page of the list is as shareable as a search of it.
 */
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

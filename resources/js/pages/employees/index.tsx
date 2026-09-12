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
    /** A workgroup id, or '' for every workgroup. Includes everything under it (see EmployeeController::index). */
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

/** Only the props the list itself owns come back on a filter change; `workgroups` and `tags` stay put. */
const PARTIAL = ['employees', 'pagination', 'filters'];

/**
 * The declared width for every fixed column. Both the `TableHead`s below and
 * the table's `min-w` floor (`TABLE_MIN_WIDTH`) read from this one object, so
 * the floor cannot silently drift out of step with the columns the way a
 * hand-typed `min-w-[1040px]` did (fix round 2): the floor had been derived
 * as "798 declared + 242 flexible" as if the table rendered each column at
 * exactly its declared width. It does not — `table-layout` is `auto`
 * (the default), so a `TableHead`'s width is only a preference the browser
 * redistributes under pressure. MEASURED at this floor (1440 in brackets):
 * Workgroup 212.6px (259.1), Position 211.3px (250.7), Tags 213px (213) — all
 * short of 270/260/200 declared. `actions` is declared 68, not the 64 a bare
 * `w-16` would give it, because table-layout:auto never shrinks it below 68
 * regardless of what is asked — MEASURED identically at both floors and both
 * widths on this page and on workgroups/index.tsx, so 68 is what the column
 * reliably gets, not a rounding artifact.
 *
 * One cell still clips at this floor: "Office of the Executive Director" (a
 * workgroup name) does not fit the 212.6px Workgroup column, 4 clipped `<td>`s at 800
 * against 3 at 1440 (the two-line "Human Resource Management Section" /
 * "…Officer III" cells clip at both widths). Dropping columns responsively
 * was rejected in fix round 1 — the overflow is data-dependent — so this is
 * an accepted cost, not zero as an earlier version of this comment and of
 * `.ai/rules/pages.md` both claimed.
 */
const COLUMNS = {
    workgroup: 270,
    position: 260,
    tags: 200,
    actions: 68,
} as const;

/**
 * Not a column: what the *flexible* Employee column needs for the longest
 * real two-line name cell ("Ricardo Salazar Bautista Jr." plus a pill),
 * measured at 1440. There is no `w-[…]` for Employee — it takes whatever
 * `COLUMNS` leaves.
 */
const FLEX_MIN = 242;

/** A no-op at 1440, where the table wants 1126 regardless. */
const TABLE_MIN_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 0) + FLEX_MIN;

/**
 * Every filter lives in the query string, so the list is a link: a colleague
 * can be sent `/employees?workgroup=…&tag=night`. Defaults are dropped rather than
 * spelled out, so an unfiltered list is `/employees` and nothing else.
 */
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

/**
 * Which filters are holding rows back, in the words the controls use — §5.20
 * asks an empty filtered list to say *which* filter, not just that there is
 * one. A search says what was typed; the other three are named by their
 * control, because "the tag filter" is findable on screen and the tag's own
 * value is already visible in it.
 */
function activeFilters(filters: Filters): string[] {
    return [
        filters.search !== '' ? `the search for “${filters.search}”` : null,
        filters.workgroup !== '' ? 'the workgroup filter' : null,
        filters.tag !== '' ? 'the tag filter' : null,
        filters.exempt ? 'Exempt only' : null,
    ].filter((phrase): phrase is string => phrase !== null);
}

/** "a, b and c" — an Oxford-less list, because these are read aloud in a sentence. */
function sentenceList(parts: string[]): string {
    if (parts.length <= 1) {
        return parts.join('');
    }

    return `${parts.slice(0, -1).join(', ')} and ${parts.at(-1)}`;
}

/**
 * A row's own colour comes from the person's name (§7.3), never from a field,
 * so the same person is the same tint on every screen. The number is the
 * second line rather than a column of its own: it is how this person is
 * identified in every DTR the agency files, so it belongs to the name.
 *
 * The badge is the row's whole state, and it is here rather than in a column
 * of its own. MEASURED: a Standing column carrying Active / Exempt /
 * Separated put a green pill on twenty-eight of thirty rows and said nothing
 * with any of them — a column of identical marks is noise, and it pulled the
 * eye off the names. Only the exceptions are worth marking, and an exception
 * belongs beside the person it is about, exactly as the agencies list marks
 * the one you are inside. Someone who has left also drops the row's ink
 * (§5.13's locked row), so the state does not rest on the badge alone.
 */
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

/**
 * Tags are free-form and there can be twenty of them, so the row shows the
 * first two and counts the rest — a row that grows to fit its labels breaks
 * the 52px rhythm the whole table is read by. The overflow chip names them in
 * a tooltip; the filter above is how you actually work with them.
 */
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

    // Typing reloads the list, not the page: only the three props the table
    // reads come back, the scroll position and this box's own state stay put,
    // and the history entry is replaced so a search does not fill the back
    // button with keystrokes.
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

    /*
     * `search`, not `filters.search`: `filters` holds what the server last
     * confirmed, so flipping a filter while the 250ms debounce is still in
     * flight built the query from the previous term and silently threw away
     * what had just been typed. The box is the truth about the box. `next`
     * still comes last, so the Clear filters button's own `search: ''` wins.
     */
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
                // The screen a freshly set-up agency lands on: one panel, one
                // sentence that teaches how a person becomes a DTR, and the
                // same action the heading row offers (§5.20).
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
                    /*
                      Two rules meet on this panel and only one arrangement
                      satisfies both.
                      • §5.13: wide content scrolls rather than squeezing.
                      • The sticky-head trap (components.md, commit 7f2414a):
                        any `overflow` ancestor between the `th` and the
                        shell's scroller becomes the sticky scrollport, and the
                        head silently stops pinning under the 56px bar. CSS
                        cannot give one axis `auto` and the other `visible`.
                      So the scrolling is the SHELL's, not this panel's:
                      `min-w-min` stops the panel from squeezing its table
                      below the table's own min-content, the panel's border
                      keeps wrapping it, `table-container` stays
                      `overflow-x-clip` with nothing left to clip, and
                      #main-content — already `overflow-auto` — is what
                      scrolls sideways. The head keeps sticking to it.

                      MEASURED at an 800px viewport with the sidebar expanded:
                      before, a 646px table sat in a 486px card, `scrollWidth >
                      clientWidth` with nothing scrollable, and `Actions for …`
                      landed at x 871-903 — outside the viewport, taking Edit
                      and Remove with it. Tags was fully clipped and Workgroup read
                      "Fi…". After: the card is 648, the table is whole, and
                      the shell scrolls 128px. Dropping columns instead was
                      rejected: the overflow is data-dependent (one long tag
                      moves it), so hiding a column narrows the odds of an
                      unreachable action rather than removing it.
                    */
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

                        {/* A combobox rather than a select: an agency's tree can
                            run to hundreds of workgroups, and the indented rows are
                            how you tell two divisions of the same name apart.
                            The trigger shows the bare name — the indentation is
                            information about the list, not about the choice. */}
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
                            {/* Below the trigger, not over it: the item-aligned
                                default covers the trigger for as long as the
                                list is open. */}
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

                    {/*
                      The floor the panel's `min-w-min` then inherits, derived
                      from `COLUMNS` and `FLEX_MIN` above rather than
                      hand-typed — see that comment for what actually renders
                      at this floor (table-layout:auto only ever treats a
                      declared width as a preference) and for the one cell
                      that still clips. Without a floor at all the panel stops
                      at the table's *min-content*, which is the maximally
                      squeezed layout: the `max-w-0` truncating cells collapse
                      to nothing and Workgroup still reads "Fi…".
                    */}
                    <Table style={{ minWidth: TABLE_MIN_WIDTH }}>
                        <TableCaption className="sr-only mt-0">Employees</TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead>Employee</TableHead>
                                {/* MEASURED at 1440: "Human Resource Management Section" needs 270 and a
                                    CSC position title needs 260; below either, the column that
                                    tells you where someone works clips first. */}
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
                                    {/* The head and the filters stay on screen,
                                        because a filter can bring rows back
                                        (§5.13) — so what emptied the table is
                                        also the way out of it. */}
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
                                            // A page number past the end of the
                                            // list. Nobody links here, but the
                                            // query string is public.
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
                                        // §5.13's locked row: someone who has
                                        // left keeps their pill and drops their
                                        // ink, so the state reads without
                                        // relying on colour.
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

/**
 * A page link while there is a page to go to, and a disabled button when there
 * is not — a link with nowhere to go is worse than an obviously spent control.
 * Real hrefs, so a page of the list is as shareable as a filter of it.
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
            <Link href={href} aria-label={label} only={PARTIAL} preserveScroll>
                {children}
            </Link>
        </Button>
    );
}

/**
 * The row's own actions. A view-only reader gets no menu at all rather than a
 * menu of things they cannot do; the fault-coloured item names what it removes
 * and says what survives, because a removal here is a soft delete that keeps
 * every daily time record already filed.
 */
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

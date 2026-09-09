import { Link, router } from '@inertiajs/react';
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    LinkIcon,
    MailIcon,
    MoreHorizontalIcon,
    PencilIcon,
    PlusIcon,
    SearchIcon,
    XCircleIcon,
} from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { toast } from 'sonner';
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
import { StatusPill } from '@/components/ui/badge';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
import { create, destroy, edit, index, invite } from '@/routes/users';
import type { User } from '@/types';

/**
 * The list row's shape: `UserResource` plus the three things it computes for
 * this screen. Declared here rather than in types/index.d.ts because nothing
 * else consumes them — the Access label, the status and the invitation link
 * exist for this table and its row menu.
 */
interface UserRow extends User {
    access: { label: string; preset: string | null };
    status: 'active' | 'invited';
    invitation_url: string | null;
}

interface Filters {
    search: string;
    access: string;
    status: string;
    sort: 'name' | 'access' | 'status';
    direction: 'asc' | 'desc';
}

interface Option {
    value: string;
    label: string;
}

interface Pagination {
    from: number | null;
    to: number | null;
    total: number;
    previous: string | null;
    next: string | null;
}

/** Only the props the list itself owns come back on a filter change. */
const PARTIAL = ['users', 'pagination', 'filters'];

/**
 * Everything the list is looking at travels in the query string, so the view
 * is a link: `/users?status=invited` is what the dashboard's "Invitations not
 * yet accepted" row points at. Defaults are dropped rather than spelled out,
 * so an unfiltered list is `/users` and nothing else.
 */
function query(filters: Filters, page?: string): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.search !== '') {
        params.search = filters.search;
    }

    if (filters.access !== 'any') {
        params.access = filters.access;
    }

    if (filters.status !== 'any') {
        params.status = filters.status;
    }

    if (filters.sort !== 'name' || filters.direction !== 'asc') {
        params.sort = filters.sort;
        params.direction = filters.direction;
    }

    if (page) {
        params.page = page;
    }

    return params;
}

/** A row's own colour comes from the person's name (§7.3), never from a field. */
function Person({ user }: { user: UserRow }) {
    return (
        <span className="flex items-center gap-2.5">
            <Avatar aria-hidden>
                <AvatarFallback tint={avatarTint(user.name)}>{initials(user.name)}</AvatarFallback>
            </Avatar>
            <span className="min-w-0">
                <span className="block truncate text-sm leading-[18px] font-medium">{user.name}</span>
                <span className="text-muted-foreground block truncate text-xs leading-4">{user.email}</span>
            </span>
        </span>
    );
}

export default function Index({
    users,
    pagination,
    filters,
    accesses,
    statuses,
}: {
    users: UserRow[];
    pagination: Pagination;
    filters: Filters;
    accesses: Option[];
    statuses: Option[];
}) {
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

    function go(next: Partial<Filters>) {
        router.get(index.url(), query({ ...filters, ...next }), {
            only: PARTIAL,
            preserveState: true,
            preserveScroll: true,
        });
    }

    function page(url: string) {
        router.get(url, {}, { only: PARTIAL, preserveState: true });
    }

    /**
     * A head is sortable, so it is a real button inside the `th`, and the
     * direction belongs to the column, so `aria-sort` sits on the `th`
     * (§5.13). Clicking the sorted column reverses it; clicking another
     * starts that column ascending.
     */
    function sortable(column: Filters['sort'], label: string, className?: string): ReactNode {
        const sorted = filters.sort === column;

        return (
            <TableHead
                className={className}
                aria-sort={sorted ? (filters.direction === 'asc' ? 'ascending' : 'descending') : 'none'}
            >
                <TableSortButton
                    onClick={() =>
                        go({ sort: column, direction: sorted && filters.direction === 'asc' ? 'desc' : 'asc' })
                    }
                >
                    {label}
                </TableSortButton>
            </TableHead>
        );
    }

    async function copyInvitation(url: string) {
        try {
            await navigator.clipboard.writeText(url);
            toast.success('Invitation link copied');
        } catch {
            toast.error('Could not copy the invitation link.');
        }
    }

    const inviteUser = (
        <Button asChild>
            <Link href={create()}>
                <PlusIcon aria-hidden />
                Invite user
            </Link>
        </Button>
    );

    const filtered = filters.search !== '' || filters.access !== 'any' || filters.status !== 'any';

    return (
        <AppLayout>
            <PageHeader
                title="Users"
                description="People who can sign in, and what each of them can do"
                actions={inviteUser}
            />

            {users.length === 0 && !filtered ? (
                // The screen a new agency lands on: one panel, one sentence
                // that says how access works, and the same action the bar
                // offers (§5.20).
                <Card className="p-8">
                    <EmptyState
                        className="py-0"
                        title="No users yet"
                        description="Sign-in is by invitation. Invite the people in your office who keep the daily time records, and they choose their own password."
                        action={inviteUser}
                    />
                </Card>
            ) : (
                <Card
                    // ui/table.tsx wraps the table in `overflow-x-auto`, and an
                    // overflow ancestor becomes the sticky scrollport — which
                    // silently stops the head pinning under the 56px bar
                    // (components.md). Four narrow columns have nothing to
                    // scroll sideways, so the container is handed back to the
                    // shell's own scroller.
                >
                    <CardHeader className="min-h-[60px] flex-wrap">
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
                                aria-label="Search users"
                                placeholder="Search name or email"
                                className="w-[260px] pl-[34px]"
                            />
                        </span>
                        <Select value={filters.access} onValueChange={(value) => go({ access: value })}>
                            <SelectTrigger aria-label="Filter by access" className="justify-start gap-2">
                                <span className="text-muted-foreground font-normal">Access</span>
                                <SelectValue />
                            </SelectTrigger>
                            {/* Below the trigger, not over it: the item-aligned
                                default covers the trigger's own "Access" label
                                for as long as the list is open. */}
                            <SelectContent position="popper" align="start" sideOffset={6}>
                                {accesses.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={filters.status} onValueChange={(value) => go({ status: value })}>
                            <SelectTrigger aria-label="Filter by status" className="justify-start gap-2">
                                <span className="text-muted-foreground font-normal">Status</span>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent position="popper" align="start" sideOffset={6}>
                                {statuses.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <CardDescription className="ml-auto font-medium tabular-nums">
                            {pagination.total} {pagination.total === 1 ? 'user' : 'users'}
                        </CardDescription>
                    </CardHeader>

                    <Table>
                        <TableCaption className="sr-only mt-0">Users</TableCaption>
                        <TableHeader sticky>
                            <TableRow>
                                {sortable('name', 'Name')}
                                {sortable('access', 'Access', 'w-[210px]')}
                                {sortable('status', 'Status', 'w-[150px]')}
                                <TableHead className="w-16">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.length === 0 ? (
                                <TableRow className="hover:[&>td]:bg-transparent">
                                    <TableCell colSpan={4} className="h-auto whitespace-normal">
                                        <EmptyState
                                            className="py-6"
                                            title="No matching users"
                                            description="Nothing here matches the search and filters above. Clear them to see everyone who can sign in."
                                            action={
                                                <Button variant="outline" asChild>
                                                    <Link href={index()}>Clear filters</Link>
                                                </Button>
                                            }
                                        />
                                    </TableCell>
                                </TableRow>
                            ) : (
                                users.map((user) => (
                                    <TableRow key={user.id}>
                                        <TableCell className="h-[52px]">
                                            <Person user={user} />
                                        </TableCell>
                                        <TableCell className={user.access.preset ? undefined : 'text-muted-foreground'}>
                                            {user.access.label}
                                        </TableCell>
                                        <TableCell>
                                            {user.status === 'invited' ? (
                                                <StatusPill variant="attention">Invited</StatusPill>
                                            ) : (
                                                <StatusPill variant="positive">Active</StatusPill>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <RowMenu user={user} onCopy={copyInvitation} />
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>

                    <CardFooter>
                        <span>
                            {pagination.total === 0
                                ? 'No users'
                                : `${pagination.from} to ${pagination.to} of ${pagination.total}`}
                        </span>
                        <span className="flex-1" />
                        <Button
                            variant="outline"
                            size="icon-sm"
                            aria-label="Previous page"
                            disabled={!pagination.previous}
                            onClick={() => pagination.previous && page(pagination.previous)}
                        >
                            <ChevronLeftIcon aria-hidden strokeWidth={1.5} />
                        </Button>
                        <Button
                            variant="outline"
                            size="icon-sm"
                            aria-label="Next page"
                            disabled={!pagination.next}
                            onClick={() => pagination.next && page(pagination.next)}
                        >
                            <ChevronRightIcon aria-hidden strokeWidth={1.5} />
                        </Button>
                    </CardFooter>
                </Card>
            )}
        </AppLayout>
    );
}

/**
 * The row's own actions, in the only other place a shadow is allowed (§5.14).
 * Only what the row can actually do is offered: an accepted invitation has
 * nothing to re-send and no link left to copy, and the fault-coloured item
 * names what it destroys — an outstanding invitation, or an account.
 */
function RowMenu({ user, onCopy }: { user: UserRow; onCopy: (url: string) => void }) {
    const invited = user.status === 'invited';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${user.name}`}>
                    <MoreHorizontalIcon aria-hidden strokeWidth={1.5} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-[252px]">
                <DropdownMenuItem asChild>
                    <Link href={edit(user)}>
                        <PencilIcon aria-hidden strokeWidth={1.5} />
                        Edit permissions
                    </Link>
                </DropdownMenuItem>
                {invited && (
                    <DropdownMenuItem onSelect={() => router.post(invite.url(user))}>
                        <MailIcon aria-hidden strokeWidth={1.5} />
                        Resend invitation
                    </DropdownMenuItem>
                )}
                {user.invitation_url && (
                    <DropdownMenuItem onSelect={() => onCopy(user.invitation_url as string)}>
                        <LinkIcon aria-hidden strokeWidth={1.5} />
                        Copy invitation link
                    </DropdownMenuItem>
                )}
                <DropdownMenuSeparator />
                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <DropdownMenuItem variant="destructive" onSelect={(event) => event.preventDefault()}>
                            <XCircleIcon aria-hidden strokeWidth={1.5} />
                            {invited ? 'Cancel invitation' : 'Remove user'}
                        </DropdownMenuItem>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                {invited ? `Cancel the invitation to ${user.email}?` : `Remove ${user.name}?`}
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                {invited
                                    ? 'The link in their email stops working and the account goes away. You can invite them again.'
                                    : 'They lose access immediately. You can invite them again with the same address.'}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>{invited ? 'Keep invitation' : 'Keep user'}</AlertDialogCancel>
                            <AlertDialogAction variant="destructive" onClick={() => router.delete(destroy.url(user))}>
                                {invited ? 'Cancel invitation' : 'Remove user'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

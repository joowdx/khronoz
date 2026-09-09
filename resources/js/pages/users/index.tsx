import { Form, Link } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { create, destroy, edit, index, invite } from '@/routes/users';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import type { User } from '@/types';

export default function Index({
    users,
    filters,
    permissions_total,
}: {
    users: User[];
    filters: { search: string };
    permissions_total: number;
}) {
    const inviteUser = (
        <Button asChild>
            <Link href={create()}>Invite user</Link>
        </Button>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'Users' }]}>
            <PageHeader title="Users" actions={inviteUser} />
            <Form {...index.form()} className="flex items-end gap-2">
                {({ processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="search">Search</Label>
                            <Input
                                id="search"
                                name="search"
                                type="search"
                                defaultValue={filters.search}
                                placeholder="Name or email"
                                className="w-64"
                            />
                        </div>
                        <Button type="submit" variant="secondary" disabled={processing}>
                            Search
                        </Button>
                    </>
                )}
            </Form>
            {users.length === 0 ? (
                <EmptyState title="No users yet" action={inviteUser} />
            ) : (
                <Card>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Access</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.map((user) => {
                                    const invited = Boolean(user.invited_at) && !user.email_verified_at;
                                    const isAdmin = user.permissions.length === permissions_total;

                                    return (
                                        <TableRow key={user.id}>
                                            <TableCell>
                                                <div className="font-medium">{user.name}</div>
                                                <div className="text-muted-foreground text-sm">{user.email}</div>
                                            </TableCell>
                                            <TableCell>
                                                {isAdmin ? (
                                                    <Badge>Admin</Badge>
                                                ) : (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <Badge variant="secondary">
                                                                {user.permissions.length} permissions
                                                            </Badge>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            {Object.entries(user.permission_groups).map(
                                                                ([group, groupPermissions]) => (
                                                                    <div key={group}>
                                                                        <span className="font-medium">{group}: </span>
                                                                        {groupPermissions
                                                                            .map((permission) => permission.label)
                                                                            .join(', ')}
                                                                    </div>
                                                                ),
                                                            )}
                                                        </TooltipContent>
                                                    </Tooltip>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={invited ? 'outline' : 'secondary'}>
                                                    {invited ? 'Invited' : 'Active'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Actions for ${user.name}`}
                                                        >
                                                            <MoreHorizontal />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem asChild>
                                                            <Link href={edit(user)}>Edit</Link>
                                                        </DropdownMenuItem>
                                                        {invited && (
                                                            <Form {...invite.form(user)}>
                                                                {({ processing }) => (
                                                                    <DropdownMenuItem asChild>
                                                                        <button
                                                                            type="submit"
                                                                            disabled={processing}
                                                                            className="w-full text-left"
                                                                        >
                                                                            Resend invitation
                                                                        </button>
                                                                    </DropdownMenuItem>
                                                                )}
                                                            </Form>
                                                        )}
                                                        <AlertDialog>
                                                            <AlertDialogTrigger asChild>
                                                                <DropdownMenuItem
                                                                    variant="destructive"
                                                                    onSelect={(event) => event.preventDefault()}
                                                                >
                                                                    Remove
                                                                </DropdownMenuItem>
                                                            </AlertDialogTrigger>
                                                            <AlertDialogContent>
                                                                <AlertDialogHeader>
                                                                    <AlertDialogTitle>
                                                                        Remove {user.name}?
                                                                    </AlertDialogTitle>
                                                                    <AlertDialogDescription>
                                                                        They lose access immediately.
                                                                    </AlertDialogDescription>
                                                                </AlertDialogHeader>
                                                                <AlertDialogFooter>
                                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                    <Form {...destroy.form(user)}>
                                                                        {({ processing }) => (
                                                                            <AlertDialogAction
                                                                                type="submit"
                                                                                variant="destructive"
                                                                                disabled={processing}
                                                                            >
                                                                                Remove
                                                                            </AlertDialogAction>
                                                                        )}
                                                                    </Form>
                                                                </AlertDialogFooter>
                                                            </AlertDialogContent>
                                                        </AlertDialog>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            )}
        </AppLayout>
    );
}

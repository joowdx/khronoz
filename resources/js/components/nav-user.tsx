import { Form, usePage } from '@inertiajs/react';
import { LogOut, UserRound } from 'lucide-react';
import { destroy } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { SidebarMenu, SidebarMenuAction, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import type { SharedProps } from '@/types';

export function NavUser() {
    // `auth` is only shared from Task 6 on; tolerate it being absent at
    // runtime even though SharedProps declares it as required.
    const { auth } = usePage<SharedProps>().props;
    const user = auth?.user;

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" className="cursor-default hover:bg-transparent">
                    <Avatar className="size-8 rounded-md">
                        <AvatarFallback className="rounded-md">
                            <UserRound className="size-4" />
                        </AvatarFallback>
                    </Avatar>
                    <div className="grid flex-1 text-left text-sm leading-tight">
                        <span className="truncate font-medium">{user?.name ?? 'Signed out'}</span>
                        <span className="text-sidebar-foreground/70 truncate text-xs">{user?.email ?? ''}</span>
                    </div>
                </SidebarMenuButton>
                {user && (
                    <Form {...destroy.form()}>
                        {({ processing }) => (
                            <SidebarMenuAction type="submit" title="Log out" disabled={processing}>
                                <LogOut />
                                <span className="sr-only">Log out</span>
                            </SidebarMenuAction>
                        )}
                    </Form>
                )}
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

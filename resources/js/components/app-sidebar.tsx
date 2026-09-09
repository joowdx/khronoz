import { Link, usePage } from '@inertiajs/react';
import { Building2, LayoutDashboard, Users } from 'lucide-react';
import { AgencySwitcher } from '@/components/agency-switcher';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { index as agenciesIndex } from '@/routes/platform/agencies';
import { index as usersIndex } from '@/routes/users';
import type { SharedProps } from '@/types';

export function AppSidebar() {
    // `auth` is only shared from Task 6 on; tolerate it being absent at
    // runtime even though SharedProps declares it as required.
    const { auth } = usePage<SharedProps>().props;
    const can = useCan();

    return (
        <Sidebar>
            <SidebarHeader>
                <AgencySwitcher />
            </SidebarHeader>
            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupLabel>Workspace</SidebarGroupLabel>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild>
                                <Link href={dashboard()}>
                                    <LayoutDashboard />
                                    <span>Dashboard</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                        {can('users.manage') && (
                            <SidebarMenuItem>
                                <SidebarMenuButton asChild>
                                    <Link href={usersIndex()}>
                                        <Users />
                                        <span>Users</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        )}
                    </SidebarMenu>
                </SidebarGroup>
                {auth?.user?.platform && (
                    <SidebarGroup>
                        <SidebarGroupLabel>Platform</SidebarGroupLabel>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton asChild>
                                    <Link href={agenciesIndex()}>
                                        <Building2 />
                                        <span>Agencies</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                )}
            </SidebarContent>
            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

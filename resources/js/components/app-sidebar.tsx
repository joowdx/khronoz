import { Link, usePage } from '@inertiajs/react';
import { Building2, LayoutDashboard } from 'lucide-react';
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
import { dashboard } from '@/routes';
import { index as agenciesIndex } from '@/routes/platform/agencies';
import type { SharedProps } from '@/types';

export function AppSidebar() {
    // `auth` is only shared from Task 6 on; tolerate it being absent at
    // runtime even though SharedProps declares it as required.
    const { auth } = usePage<SharedProps>().props;

    return (
        <Sidebar>
            <SidebarHeader>
                <AgencySwitcher />
            </SidebarHeader>
            <SidebarContent>
                {/*
                    Nav groups are added by the tasks that create their routes:
                    Task 8 adds Platform → Agencies.
                    Task 9 adds Workspace → Users.
                */}
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

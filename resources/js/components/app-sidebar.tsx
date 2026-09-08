import { usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader } from '@/components/ui/sidebar';
import type { SharedProps } from '@/types';

export function AppSidebar() {
    // `agency` is only shared from Task 6 on; tolerate it being absent at
    // runtime even though SharedProps declares it as required.
    const { agency } = usePage<SharedProps>().props;

    return (
        <Sidebar>
            <SidebarHeader>
                {/* Task 8 replaces this static block with <AgencySwitcher />. */}
                <div className="flex items-center gap-2 px-2 py-1.5">
                    <div className="bg-sidebar-primary text-sidebar-primary-foreground flex size-8 items-center justify-center rounded-md">
                        <Building2 className="size-4" />
                    </div>
                    <div className="grid flex-1 text-left text-sm leading-tight">
                        <span className="truncate font-semibold">khronoz</span>
                        <span className="text-sidebar-foreground/70 truncate text-xs">
                            {agency?.name ?? 'Platform'}
                        </span>
                    </div>
                </div>
            </SidebarHeader>
            <SidebarContent>
                {/*
                    Nav groups are added by the tasks that create their routes:
                    Task 6 adds Workspace → Dashboard.
                    Task 8 adds Platform → Agencies.
                    Task 9 adds Workspace → Users.
                */}
            </SidebarContent>
            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

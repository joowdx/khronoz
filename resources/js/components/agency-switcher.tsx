import { Form, Link, usePage } from '@inertiajs/react';
import { Building2, ChevronsUpDown } from 'lucide-react';
import { enter, index, leave } from '@/routes/platform/agencies';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import type { SharedProps } from '@/types';

/**
 * The sidebar header for every signed-in user. A platform user gets a
 * dropdown that lets them enter any agency (adopting it as their tenant,
 * see SetTenant) or leave back to the platform itself; everyone else has
 * exactly one agency and sees its name as plain, non-interactive text.
 */
export function AgencySwitcher() {
    // `auth`/`agency`/`agencies` are only shared from Task 6 on; tolerate
    // them being absent at runtime even though SharedProps declares them
    // required, the same way app-sidebar.tsx and app-layout.tsx already do.
    const { auth, agency, agencies } = usePage<SharedProps>().props;

    const identity = (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex size-8 items-center justify-center rounded-md">
                <Building2 className="size-4" />
            </div>
            <div className="grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-semibold">khronoz</span>
                <span className="text-sidebar-foreground/70 truncate text-xs">{agency?.name ?? 'Platform'}</span>
            </div>
        </>
    );

    if (!auth?.user?.platform) {
        return <div className="flex items-center gap-2 px-2 py-1.5">{identity}</div>;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton size="lg">
                            {identity}
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="start"
                        side="bottom"
                    >
                        {agency && !agency.platform && (
                            <>
                                <Form {...leave.form()}>
                                    {() => (
                                        <DropdownMenuItem asChild>
                                            <button type="submit" className="w-full text-left">
                                                Platform
                                            </button>
                                        </DropdownMenuItem>
                                    )}
                                </Form>
                                <DropdownMenuSeparator />
                            </>
                        )}
                        {(agencies ?? []).map((a) => (
                            <Form key={a.id} {...enter.form(a)}>
                                {() => (
                                    <DropdownMenuItem asChild>
                                        <button type="submit" className="w-full text-left">
                                            {a.name}
                                        </button>
                                    </DropdownMenuItem>
                                )}
                            </Form>
                        ))}
                        <DropdownMenuItem asChild>
                            <Link href={index()} className="w-full">
                                Manage agencies
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

import { usePage } from '@inertiajs/react';
import { Building2, LayoutGrid, UserRoundCheck } from 'lucide-react';
import { AgencySwitcher } from '@/components/agency-switcher';
import { DayStrip } from '@/components/day-strip';
import { NavMain, type NavGroup } from '@/components/nav-main';
import { Sidebar } from '@/components/ui/sidebar';
import { UserMenu } from '@/components/user-menu';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { index as agenciesIndex } from '@/routes/platform/agencies';
import { index as usersIndex } from '@/routes/users';
import type { SharedProps } from '@/types';

/**
 * The application shell's left column: 248 wide, on the sidebar grey, with a
 * 1px right rule. It carries, in order, whose records you are looking at,
 * today on the 06 → 30 scale, where you can go, and who you are.
 *
 * Two parts of the artboard are deliberately absent in Milestone 1:
 *
 * - The employee search field, because it searches employees and `Employee`
 *   arrives in Milestone 2. A search box that finds nothing is worse than no
 *   search box.
 * - The collapsed 64px rail (§5.4), which exists so the roster grid can have
 *   the width. It arrives with the roster in Milestone 3.
 *
 * On a narrow viewport the whole column becomes a sheet, which shadcn's
 * `Sidebar` already provides; `page-header.tsx` carries the trigger that opens
 * it.
 */
export function AppSidebar() {
    const { auth } = usePage<SharedProps>().props;
    const can = useCan();
    const platform = auth?.user?.platform ?? false;

    /*
     * Navigation is data. A milestone adds a group to this array; it does not
     * add markup to nav-main.tsx. Only what exists is rendered, so the groups
     * §6.2 names arrive as their models land:
     *
     *   Organization        Units · Employees                   Milestone 2
     *   Scheduling          Shifts · Schedules · Rosters        Milestone 3
     *   Calendar            Calendar                            Milestone 4
     *   Terminals           Terminals                           Milestone 5
     *   Daily time records  Workdays · Ledgers                  Milestone 6
     *
     * §10 also lists a Settings item for Milestone 1. There is no settings
     * screen yet, so it is not rendered; it joins the ungrouped run under
     * Users when the agency-profile screens land.
     */
    const groups: NavGroup[] = [
        {
            items: [
                { title: 'Dashboard', href: dashboard().url, icon: LayoutGrid },
                ...(can('users.manage') ? [{ title: 'Users', href: usersIndex().url, icon: UserRoundCheck }] : []),
            ],
        },
        ...(platform
            ? [{ label: 'Platform', items: [{ title: 'Agencies', href: agenciesIndex().url, icon: Building2 }] }]
            : []),
    ];

    return (
        <Sidebar>
            <AgencySwitcher />
            <DayStrip />
            <NavMain groups={groups} />
            <div className="border-border border-t px-2.5 py-2">
                <UserMenu />
            </div>
            <p className="text-muted-foreground px-[18px] pb-3.5 text-xs leading-4 font-bold tracking-[-0.004em]">
                khronoz
            </p>
        </Sidebar>
    );
}

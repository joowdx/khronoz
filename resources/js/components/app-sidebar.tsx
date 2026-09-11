import { usePage } from '@inertiajs/react';
import { Building2, CalendarDays, CalendarOff, CloudRainWind, FileDown, Fingerprint, LayoutGrid, Network, ScanLine, UserRoundCheck, UsersRound } from 'lucide-react';
import { AgencySwitcher } from '@/components/agency-switcher';
import { DayStrip } from '@/components/day-strip';
import { NavMain, type NavGroup } from '@/components/nav-main';
import { Sidebar } from '@/components/ui/sidebar';
import { UserMenu } from '@/components/user-menu';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { index as employeesIndex } from '@/routes/employees';
import { index as agenciesIndex } from '@/routes/platform/agencies';
import { index as terminalsIndex } from '@/routes/terminals';
import { index as exemptionsIndex } from '@/routes/exemptions';
import { index as holidaysIndex } from '@/routes/holidays';
import { index as suspensionsIndex } from '@/routes/suspensions';
import { index as syncsIndex } from '@/routes/syncs';
import { index as timelogsIndex } from '@/routes/timelogs';
import { index as workgroupsIndex } from '@/routes/workgroups';
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
    const { auth, agency } = usePage<SharedProps>().props;
    const can = useCan();
    const platform = auth?.user?.platform ?? false;

    /*
     * Workgroups and employees belong to an agency, and the platform row is the one
     * agency that may not have any: `employees`' agency_not_platform trigger
     * refuses the insert (P0001), so for a superuser who has not entered an
     * agency yet the whole group leads nowhere — Add employee would answer
     * with an uncaught 500. SetTenant defaults such a user's tenant to the
     * platform agency itself and Gate::before grants them every ability, so
     * neither the tenant being set nor the permission check catches this; what
     * decides it is whether the agency they are in is a real one. §10 and
     * components.md agree that a nav item leading nowhere is worse than an
     * absent one, so the group waits until they enter an agency — which the
     * sidebar's own switcher, two blocks above, is how they do it.
     */
    const insideAgency = agency !== null && agency !== undefined && !agency.platform;

    /*
     * Navigation is data. A milestone adds a group to this array; it does not
     * add markup to nav-main.tsx. Only what exists is rendered, so the groups
     * §6.2 names arrive as their models land:
     *
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
        ...(insideAgency && can('organization.view')
            ? [
                  {
                      label: 'Organization',
                      items: [
                          { title: 'Workgroups', href: workgroupsIndex().url, icon: Network },
                          { title: 'Employees', href: employeesIndex().url, icon: UsersRound },
                      ],
                  },
              ]
            : []),
        // Milestone 4's layer: what changes what was expected of a day.
        ...(insideAgency && can('calendar.view')
            ? [
                  {
                      label: 'Calendar',
                      items: [
                          { title: 'Holidays', href: holidaysIndex().url, icon: CalendarDays },
                          { title: 'Suspensions', href: suspensionsIndex().url, icon: CloudRainWind },
                          { title: 'Exemptions', href: exemptionsIndex().url, icon: CalendarOff },
                      ],
                  },
              ]
            : []),
        // Milestone 5. Grouped on its own rather than folded into
        // Organization because a terminal is equipment, not a box on the org
        // chart, and the permission that gates it is its own
        // (`terminals.view`) — the sidebar mirrors the permission matrix, not
        // the database's table list.
        ...(insideAgency && can('terminals.view')
            ? [
                  {
                      label: 'Terminals',
                      items: [
                          { title: 'Terminals', href: terminalsIndex().url, icon: Fingerprint },
                          { title: 'Timelogs', href: timelogsIndex().url, icon: ScanLine },
                          { title: 'Imports', href: syncsIndex().url, icon: FileDown },
                      ],
                  },
              ]
            : []),
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

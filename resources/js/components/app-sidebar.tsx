import { usePage } from '@inertiajs/react';
import {
    AlarmClock,
    BookOpen,
    Building2,
    CalendarDays,
    CalendarOff,
    ClipboardList,
    CloudRainWind,
    CopyPlus,
    FileDown,
    Fingerprint,
    LayoutGrid,
    Network,
    Repeat,
    ScanLine,
    SquareStack,
    Table2,
    UserRoundCheck,
    UsersRound,
} from 'lucide-react';
import { AgencySwitcher } from '@/components/agency-switcher';
import { DayStrip } from '@/components/day-strip';
import { NavMain, type NavGroup } from '@/components/nav-main';
import { Sidebar } from '@/components/ui/sidebar';
import { UserMenu } from '@/components/user-menu';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { index as defaultsIndex } from '@/routes/defaults';
import { index as employeesIndex } from '@/routes/employees';
import { index as agenciesIndex } from '@/routes/platform/agencies';
import { index as terminalsIndex } from '@/routes/terminals';
import { index as exemptionsIndex } from '@/routes/exemptions';
import { index as holidaysIndex } from '@/routes/holidays';
import { index as overtimesIndex } from '@/routes/overtimes';
import { index as rostersIndex } from '@/routes/rosters';
import { index as schedulesIndex } from '@/routes/schedules';
import { index as shiftsIndex } from '@/routes/shifts';
import { index as suspensionsIndex } from '@/routes/suspensions';
import { index as teamsIndex } from '@/routes/teams';
import { index as syncsIndex } from '@/routes/syncs';
import { index as timelogsIndex } from '@/routes/timelogs';
import { index as workdaysIndex } from '@/routes/workdays';
import { index as ledgersIndex } from '@/routes/ledgers';
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
     *   Daily time records  Workdays · Ledgers                  Milestone 6 (this group)
     *
     * §10 also lists a Settings item for Milestone 1. There is no settings
     * screen yet, so it is not rendered; it joins the ungrouped run under
     * Users when the agency-profile screens land.
     */
    const platformItems = [
        ...(can('users.manage') ? [{ title: 'Users', href: usersIndex().url, icon: UserRoundCheck }] : []),
        ...(platform ? [{ title: 'Agencies', href: agenciesIndex().url, icon: Building2 }] : []),
    ];

    const groups: NavGroup[] = [
        {
            items: [{ title: 'Dashboard', href: dashboard().url, icon: LayoutGrid }],
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
        // Milestone 3's layer, and the one the product is named for. Rosters
        // first: it is the screen people come here to look at, and shifts and
        // schedules are what you edit to change what it draws.
        //
        // Gated on `insideAgency` for the same reason Organization is — `teams`
        // carries agency_not_platform and a roster needs an employee, so from
        // the platform tenant half of this group leads to a P0001 the routes
        // now 404 first (middleware.md, and OrganizationNavContractTest locks
        // the pattern).
        ...(insideAgency && can('scheduling.view')
            ? [
                  {
                      label: 'Scheduling',
                      items: [
                          { title: 'Rosters', href: rostersIndex().url, icon: Table2 },
                          { title: 'Shifts', href: shiftsIndex().url, icon: SquareStack },
                          { title: 'Schedules', href: schedulesIndex().url, icon: Repeat },
                          { title: 'Teams', href: teamsIndex().url, icon: UsersRound },
                          { title: 'Defaults', href: defaultsIndex().url, icon: CopyPlus },
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
                          { title: 'Overtime', href: overtimesIndex().url, icon: AlarmClock },
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
        ...(insideAgency && can('ledgers.view')
            ? [
                  {
                      label: 'Timesheets',
                      items: [
                          { title: 'Workdays', href: workdaysIndex().url, icon: ClipboardList },
                          { title: 'Ledgers', href: ledgersIndex().url, icon: BookOpen },
                      ],
                  },
              ]
            : []),
        // Users sits here rather than beside Dashboard (owner, 2026-09-12). It is
        // administration of the installation, not of the timetable, and it reads
        // better at the bottom next to Agencies than at the top next to the one
        // screen everybody opens.
        //
        // The group is **not** gated on `platform`, only its Agencies row is: an
        // agency administrator holding users.manage invites and manages their own
        // colleagues and must keep reaching this. Gating the group on `platform`
        // would have taken the screen away from exactly the people who use it
        // most, which is the failure OrganizationNavContractTest exists to catch
        // in the other direction — a group offering rows the tenant cannot hold.
        ...(platformItems.length > 0 ? [{ label: 'Platform', items: platformItems }] : []),
    ];

    return (
        // Collapses to §4.3's 64px rail rather than off-canvas. The trigger in
        // `page-header.tsx` is the same one either way; what changes is that
        // the nav stays reachable, which is the point of the rail — the roster
        // grid needs the width and still needs somewhere to navigate from.
        //
        // Every child below is hand-rolled rather than built on
        // `SidebarMenuButton`, so none of the primitive's own
        // `group-data-[collapsible=icon]` rules reach them; each carries its
        // own collapsed variant.
        <Sidebar collapsible="icon">
            <AgencySwitcher />
            {/*
              Dropped in the rail. It is a 24-hour ruler with a marker on it —
              at 64px wide the ticks are closer together than the marker is
              wide, so it would read as a smear rather than a time. The clock
              it shows is not lost: the same reading is in the page's own
              subtitle.
            */}
            <div className="group-data-[collapsible=icon]:hidden">
                <DayStrip />
            </div>
            <NavMain groups={groups} />
            <div className="border-border border-t px-2.5 py-2 group-data-[collapsible=icon]:px-4">
                <UserMenu />
            </div>
            <p className="text-muted-foreground px-[18px] pb-3.5 text-xs leading-4 font-bold tracking-[-0.004em] group-data-[collapsible=icon]:hidden">
                khronoz
            </p>
        </Sidebar>
    );
}

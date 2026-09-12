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
    Settings2,
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
import { index as cadencesIndex } from '@/routes/cadences';
import { edit as settingsEdit } from '@/routes/agency/settings';
import type { SharedProps } from '@/types';

export function AppSidebar() {
    const { auth, agency } = usePage<SharedProps>().props;
    const can = useCan();
    const platform = auth?.user?.platform ?? false;

    const insideAgency = agency !== null && agency !== undefined && !agency.platform;

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
                      label: 'Attendance',
                      items: [
                          { title: 'Workdays', href: workdaysIndex().url, icon: ClipboardList },
                          { title: 'Ledgers', href: ledgersIndex().url, icon: BookOpen },
                      ],
                  },
              ]
            : []),
        ...(insideAgency && can('agency.manage')
            ? [
                  {
                      label: 'Agency',
                      items: [
                          { title: 'Cadences', href: cadencesIndex().url, icon: Repeat },
                          { title: 'Settings', href: settingsEdit().url, icon: Settings2 },
                      ],
                  },
              ]
            : []),
        ...(platformItems.length > 0 ? [{ label: 'Platform', items: platformItems }] : []),
    ];

    return (
        <Sidebar collapsible="icon">
            <AgencySwitcher />
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

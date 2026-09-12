import type { ReactNode } from 'react';
import { Lock } from 'lucide-react';
import { cn } from '@/lib/utils';

const NEXT_DAY = <span className="text-acc-text text-[11px] leading-[15px] font-semibold">&#8314;&#185;</span>;

type Row = {
    day: string;
    amArrival?: ReactNode;
    amDeparture?: ReactNode;
    pmArrival?: ReactNode;
    pmDeparture?: ReactNode;
    undertime?: string;
    restDay?: boolean;
};

const ROWS: Row[] = [
    {
        day: '25',
        amArrival: '14:00',
        amDeparture: '18:00',
        pmArrival: '19:02',
        pmDeparture: '22:00',
        undertime: '0:02',
    },
    { day: '26', amArrival: '13:58', amDeparture: '18:00', pmArrival: '19:00', pmDeparture: '22:04', restDay: true },
    { day: '27', restDay: true },
    { day: '28' },
    {
        day: '29',
        amArrival: '22:00',
        amDeparture: <>02:00{NEXT_DAY}</>,
        pmArrival: <>03:00{NEXT_DAY}</>,
        pmDeparture: <>06:00{NEXT_DAY}</>,
    },
    {
        day: '30',
        amArrival: '22:03',
        amDeparture: <>02:01{NEXT_DAY}</>,
        pmArrival: <>03:00{NEXT_DAY}</>,
        pmDeparture: <>06:00{NEXT_DAY}</>,
        undertime: '0:03',
    },
];

const HEAD =
    'text-muted-foreground h-[30px] border-b px-2 pb-0 text-right align-bottom text-[11px] leading-[15px] font-semibold whitespace-nowrap';
const GROUP = 'border-b-0 pb-[3px] text-center';
const CELL = 'h-8 border-rule border-b px-2 text-right text-[13px] leading-[18px] tabular-nums';

export function Form48Preview() {
    return (
        <div className="overflow-hidden rounded-xl border">
            <div className="flex items-baseline gap-2.5 border-b px-4 pt-3 pb-[11px]">
                <span className="text-sm leading-5 font-semibold">Civil Service Form No. 48</span>
                <span className="text-muted-foreground text-xs leading-4">Delos Santos, A., September 2026</span>
                <span className="flex-1" />
                <span className="bg-acc-soft text-acc-text inline-flex h-[22px] flex-none items-center gap-1.5 rounded-full px-2.5 text-xs leading-4 font-medium">
                    <Lock className="size-4" aria-hidden="true" />
                    Locked
                </span>
            </div>

            <div className="overflow-auto">
                <table className="w-full min-w-[508px] border-separate border-spacing-0">
                    <thead>
                        <tr>
                            <th className={cn(HEAD, 'pl-4')} />
                            <th className={cn(HEAD, GROUP)} colSpan={2} scope="colgroup">
                                AM
                            </th>
                            <th className={cn(HEAD, GROUP, 'border-rule border-l')} colSpan={2} scope="colgroup">
                                PM
                            </th>
                            <th className={cn(HEAD, GROUP, 'border-rule border-l pr-4')} />
                        </tr>
                        <tr>
                            <th className={cn(HEAD, 'pl-4 text-left')} scope="col">
                                Day
                            </th>
                            <th className={HEAD} scope="col">
                                Arrival
                            </th>
                            <th className={HEAD} scope="col">
                                Departure
                            </th>
                            <th className={cn(HEAD, 'border-l-rule border-l')} scope="col">
                                Arrival
                            </th>
                            <th className={HEAD} scope="col">
                                Departure
                            </th>
                            <th className={cn(HEAD, 'border-l-rule border-l pr-4')} scope="col">
                                Undertime
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {ROWS.map((row, index) => (
                            <tr key={row.day} className={cn(row.restDay && '[&>td]:bg-weekend')}>
                                <td className={cn(CELL, 'pl-4 text-left', index === ROWS.length - 1 && 'border-b-0')}>
                                    {row.day}
                                </td>
                                <td className={cn(CELL, index === ROWS.length - 1 && 'border-b-0')}>
                                    {row.amArrival ?? ' '}
                                </td>
                                <td className={cn(CELL, index === ROWS.length - 1 && 'border-b-0')}>
                                    {row.amDeparture ?? ' '}
                                </td>
                                <td
                                    className={cn(
                                        CELL,
                                        'border-l-rule border-l',
                                        index === ROWS.length - 1 && 'border-b-0',
                                    )}
                                >
                                    {row.pmArrival ?? ' '}
                                </td>
                                <td className={cn(CELL, index === ROWS.length - 1 && 'border-b-0')}>
                                    {row.pmDeparture ?? ' '}
                                </td>
                                <td
                                    className={cn(
                                        CELL,
                                        'border-l-rule border-l pr-4 font-semibold',
                                        index === ROWS.length - 1 && 'border-b-0',
                                    )}
                                >
                                    {row.undertime ?? ' '}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="text-muted-foreground flex flex-wrap items-center gap-2.5 border-t px-4 py-3 text-xs leading-[17px]">
                <span>Certified by the employee, verified by the supervisor.</span>
                <span className="flex-1" />
                <span>{NEXT_DAY} the punch landed the next day</span>
            </div>
        </div>
    );
}

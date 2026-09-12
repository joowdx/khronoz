import { MinuteCells, PunchChain, WorkdayStatus } from '@/components/workday-cells';
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { addDay, formatDayWithWeekday } from '@/lib/dates';
import { formatMinutes } from '@/lib/minutes';
import type { LedgerView } from '@/types';
const COLUMNS = { shift: 140, status: 200, chain: 240, worked: 80, tardy: 80, undertime: 100, excess: 80, night: 80 };
const TABLE_WIDTH = Object.values(COLUMNS).reduce((sum, width) => sum + width, 200);
export function LedgerRecord({
    starts,
    ends,
    view,
    sticky = true,
}: {
    starts: string;
    ends: string;
    view: LedgerView;
    sticky?: boolean;
}) {
    const byDate = new Map(view.workdays.map((day) => [day.date, day]));
    const dates: string[] = [];
    for (let date = starts; date <= ends; date = addDay(date)) {
        dates.push(date);
    }
    return (
        <>
            <dl className="grid grid-cols-2 gap-x-6 gap-y-4 pb-8 sm:grid-cols-3 lg:grid-cols-5">
                {[
                    ['Worked', view.worked],
                    ['Tardy', view.tardy],
                    ['Undertime', view.undertime],
                    ['Overtime', view.overtime],
                    ['Night', view.night],
                ].map(([label, value]) => (
                    <div key={label} className="flex flex-col-reverse gap-1">
                        <dt className="text-muted-foreground text-sm">{label}</dt>
                        <dd className="text-2xl font-bold tabular-nums">{formatMinutes(Number(value))}</dd>
                    </div>
                ))}
            </dl>
            <Card className="min-w-min overflow-visible">
                <Table style={{ minWidth: TABLE_WIDTH }}>
                    <TableCaption className="sr-only">
                        Frozen daily attendance from {starts} through {ends}, including dates with no workday.
                    </TableCaption>
                    <TableHeader sticky={sticky}>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead style={{ width: COLUMNS.shift }}>Shift</TableHead>
                            <TableHead style={{ width: COLUMNS.status }}>Status</TableHead>
                            <TableHead style={{ width: COLUMNS.chain }}>Punches</TableHead>
                            <TableHead style={{ width: COLUMNS.worked }} numeric>
                                Worked
                            </TableHead>
                            <TableHead style={{ width: COLUMNS.tardy }} numeric>
                                Tardy
                            </TableHead>
                            <TableHead style={{ width: COLUMNS.undertime }} numeric>
                                Undertime
                            </TableHead>
                            <TableHead style={{ width: COLUMNS.excess }} numeric>
                                Excess
                            </TableHead>
                            <TableHead style={{ width: COLUMNS.night }} numeric>
                                Night
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {dates.map((date) => {
                            const day = byDate.get(date) ?? null;
                            return (
                                <TableRow key={date}>
                                    <TableCell>{formatDayWithWeekday(date)}</TableCell>
                                    <TableCell>{day?.shift_name ?? '—'}</TableCell>
                                    <TableCell>
                                        {day ? <WorkdayStatus status={day.status} premium={day.premium} /> : '—'}
                                    </TableCell>
                                    <TableCell>
                                        <PunchChain punches={day?.punches} date={day?.date} />
                                    </TableCell>
                                    <MinuteCells workday={day} />
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </Card>
            <dl className="mt-8 grid max-w-2xl gap-3 sm:grid-cols-3">
                {[
                    ['Tardy occurrences', view.tardy_occurrences],
                    ['Undertime occurrences', view.undertime_occurrences],
                    ['Absences', view.absences],
                ].map(([label, value]) => (
                    <div key={label} className="flex justify-between gap-4 border-b pb-3 text-sm">
                        <dt>{label}</dt>
                        <dd className="font-semibold tabular-nums">{value}</dd>
                    </div>
                ))}
            </dl>
        </>
    );
}

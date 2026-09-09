import { cn } from '@/lib/utils';

/**
 * A month of the agency calendar: national and local holidays, a work
 * suspension, and rest days. Static markup until Milestone 5 lands Holiday
 * and Suspension.
 */

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** November 2026 opens on a Sunday, so the day number is its own column index. */
const EVENTS: Record<number, { kind: 'holiday' | 'suspension'; note: string }> = {
    1: { kind: 'holiday', note: 'All Saints’ Day' },
    2: { kind: 'holiday', note: 'Special non-working' },
    12: { kind: 'suspension', note: 'Suspension, 10:00' },
    30: { kind: 'holiday', note: 'Bonifacio Day' },
};

const KIND = {
    holiday: 'bg-acc-soft [&_span]:text-acc-text',
    suspension: 'bg-attention-soft [&_span]:text-attention',
} as const;

const SWATCH = 'h-5 w-[22px] flex-none rounded-md border';
const LEGEND_ITEM = 'text-muted-foreground flex items-center gap-[7px] text-xs leading-4';

export function HolidayCalendar() {
    return (
        <div className="overflow-hidden rounded-xl border">
            <div className="flex items-baseline gap-2.5 border-b px-4 pt-3 pb-[11px]">
                <span className="text-lg leading-6 font-bold tracking-[-0.006em] tabular-nums">November 2026</span>
                <span className="flex-1" />
                <span className="text-muted-foreground text-xs leading-4">National, local and unit scope</span>
            </div>

            <div className="grid grid-cols-7">
                {WEEKDAYS.map((weekday) => (
                    <span
                        key={weekday}
                        className="text-muted-foreground flex h-[26px] items-center justify-center border-b text-[11px] leading-[15px] font-semibold"
                    >
                        {weekday}
                    </span>
                ))}
                {Array.from({ length: 30 }, (_, index) => {
                    const date = index + 1;
                    const event = EVENTS[date];
                    const restDay = index % 7 === 0 || index % 7 === 6;

                    return (
                        <span
                            key={date}
                            className={cn(
                                'border-rule min-h-12 px-1 pt-1 pb-[5px] md:min-h-14 md:px-1.5 md:pt-[5px] md:pb-1.5',
                                // The Saturday column ends the row, and the last
                                // row of a month needs no rule under it.
                                date % 7 === 0 ? 'border-r-0' : 'border-r',
                                date <= 28 && 'border-b',
                                event ? KIND[event.kind] : restDay && 'bg-weekend [&_span]:text-muted-foreground',
                            )}
                        >
                            <span className="block text-xs leading-4 font-semibold tabular-nums">{date}</span>
                            {event && (
                                <span className="block pt-[3px] text-[9px] leading-3 font-semibold md:text-[10px] md:leading-[13px]">
                                    {event.note}
                                </span>
                            )}
                        </span>
                    );
                })}
                {Array.from({ length: 5 }, (_, pad) => (
                    <span
                        key={pad}
                        className={cn('bg-off-fill border-rule min-h-12 md:min-h-14', pad < 4 && 'border-r')}
                        aria-hidden="true"
                    />
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-[18px] border-t px-4 py-3">
                <span className={LEGEND_ITEM}>
                    <span className={cn(SWATCH, 'bg-acc-soft border-acc-soft')} aria-hidden="true" />
                    Holiday
                </span>
                <span className={LEGEND_ITEM}>
                    <span className={cn(SWATCH, 'bg-attention-soft border-attention-soft')} aria-hidden="true" />
                    Work suspension
                </span>
                <span className={LEGEND_ITEM}>
                    <span className={cn(SWATCH, 'bg-weekend')} aria-hidden="true" />
                    Rest day
                </span>
            </div>
        </div>
    );
}

import { usePage } from '@inertiajs/react';
import { useManilaClock } from '@/hooks/use-manila-clock';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

const HOURS = 24;

const MAJOR_EVERY = 6;

const WINDOW_MINUTES = HOURS * 60;

interface DutyLane {
    count: number;
    bars: { from: number; to: number }[];
}

function useOnDuty(position: number): number | null {
    const duty = usePage<SharedProps>().props.duty as { lanes?: DutyLane[] } | undefined;

    if (duty?.lanes === undefined) {
        return null;
    }

    const minute = position * WINDOW_MINUTES;

    return duty.lanes.reduce(
        (total, lane) => (lane.bars.some((bar) => bar.from <= minute && minute < bar.to) ? total + lane.count : total),
        0,
    );
}

export function DayStrip() {
    const { day, time, position } = useManilaClock();
    const onDuty = useOnDuty(position);
    const left = `${(position * 100).toFixed(4)}%`;

    const crowdsStart = position < 0.1;
    const crowdsEnd = position > 0.9;

    return (
        <div className="px-4 pt-3.5 pb-0.5">
            <div className="flex items-baseline justify-between gap-2 pb-[5px]">
                <span className="shrink-0 text-xs leading-4 font-medium whitespace-nowrap">{day}</span>
                {onDuty !== null && (
                    <span className="text-muted-foreground shrink-0 text-xs leading-4 whitespace-nowrap tabular-nums">
                        {onDuty} on duty
                    </span>
                )}
            </div>
            <div className="relative h-[13px]" role="img" aria-label={`Now ${time}, on a day that runs 06:00 to 30:00`}>
                {Array.from({ length: HOURS + 1 }, (_, hour) => {
                    const major = hour % MAJOR_EVERY === 0;

                    return (
                        <span
                            key={hour}
                            aria-hidden
                            className={cn(
                                'absolute w-px',
                                major ? 'bg-muted-foreground top-0.5 h-2.5' : 'bg-tick top-[7px] h-[5px]',
                            )}
                            style={hour === HOURS ? { right: 0 } : { left: `${(hour / HOURS) * 100}%` }}
                        />
                    );
                })}
                <span aria-hidden className="bg-tick absolute inset-x-0 bottom-0 h-px" />
                <span aria-hidden className="bg-primary absolute top-0 h-[13px] w-px" style={{ left }} />
            </div>
            <div
                aria-hidden
                className="text-muted-foreground relative mt-0.5 h-3.5 text-[10px] leading-[14px] font-medium"
            >
                {!crowdsStart && <span className="absolute left-0">06</span>}
                {!crowdsEnd && <span className="absolute right-0">30</span>}
                <span className="text-acc-text absolute -translate-x-1/2 font-semibold" style={{ left }}>
                    {time}
                </span>
            </div>
        </div>
    );
}

import { usePage } from '@inertiajs/react';
import { useManilaClock } from '@/hooks/use-manila-clock';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/**
 * The sidebar's identity element: today on the product's one time scale.
 *
 * A day opens at 06:00 and closes at 30:00 — the same 24-hour window the lane
 * chart and the roster grid are drawn on — so a night shift that clocks out at
 * 06:00 the next morning still belongs to the day it started. The ruler is
 * that window: a tick per hour, a taller tick every six (06, 12, 18, 24, 30),
 * a 1px accent line at now, and the time under it as a small accent label.
 *
 * Geometry is `docs/design/mockups/ui.css`'s `.daystrip` family and
 * docs/design/08-interface.md §5.3, with one deliberate difference: the
 * artboard draws the ruler as a 216px SVG, because its sidebar is exactly
 * 248px wide with 16px of padding. Here the ticks are percentage-positioned
 * 1px spans instead — the same drawing, but correct in the wider sheet the
 * sidebar becomes on a narrow viewport. It is also how the lane chart's own
 * axis is built (`.axis .h { left: N% }`), so the two agree.
 *
 * Nothing animates: §1 rule 7 forbids animation on a status indicator, so the
 * marker jumps once a minute and there is nothing for `prefers-reduced-motion`
 * to turn off.
 */
const HOURS = 24;

/** 06, 12, 18, 24, 30 — the hours that carry a label or a taller tick. */
const MAJOR_EVERY = 6;

/** The window is 24 hours wide, and a lane bar is positioned in minutes of it. */
const WINDOW_MINUTES = HOURS * 60;

/**
 * The shape of `duty` the lane chart's data arrives in — the part of it this
 * strip reads, which is the counts and where each shift's bars sit on the
 * 06:00 → 30:00 scale. Declared here rather than imported: the lane chart is
 * the prop's owner and this is a second reader of two of its fields.
 */
interface DutyLane {
    count: number;
    bars: { from: number; to: number }[];
}

/**
 * How many people the roster has on duty at `minute` — the artboard's
 * "147 on duty".
 *
 * It is **derived from the lanes, not sent as a number**, and that is what
 * makes it correct a minute later: the strip re-reads the clock every minute
 * and a headcount the server computed at render time would be stale by the
 * afternoon, and flatly wrong the moment a shift changes over. A lane's people
 * are on duty exactly while one of its bars covers the minute — `[from, to)`,
 * so the shift that ends at 14:00 and the one that starts there hand over
 * without counting anybody twice.
 *
 * Null — and so nothing on screen — when the page carries no `duty` prop at
 * all. Today only the dashboard does (`DashboardController`, gated on
 * `scheduling.view`), so on every other screen this slot stays as empty as it
 * has been since Milestone 1. That is the honest answer rather than a plausible
 * one; giving it to every page means giving `duty` a home in the shared props,
 * which is a change to the Inertia middleware and not to this component.
 */
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

    // The centred time label would sit on top of the 06 / 30 end labels when
    // the marker is near either end of the window, so the end label gives way.
    // Both are decoration: the axis's own aria-label carries the reading.
    const crowdsStart = position < 0.1;
    const crowdsEnd = position > 0.9;

    return (
        <div className="px-4 pt-3.5 pb-0.5">
            <div className="flex items-baseline justify-between gap-2 pb-[5px]">
                <span className="shrink-0 text-xs leading-4 font-medium whitespace-nowrap">{day}</span>
                {/*
                  The artboard's right-hand slot (`.ds-duty`): employees whose
                  resolved shift covers this minute. It ticks with the marker
                  below it, because it is read off the same `position` — see
                  useOnDuty, which is also why the slot is absent rather than
                  zero on a page that carries no lane data.
                */}
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
                            // The closing tick is pinned to the right edge so its
                            // 1px stays inside the ruler instead of straddling it.
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

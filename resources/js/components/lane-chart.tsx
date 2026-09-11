import { useManilaClock } from '@/hooks/use-manila-clock';
import { cn } from '@/lib/utils';
import { RAMP, type Slot } from './shift-chip';

/**
 * Who is on duty, drawn on the product's one time scale (§5.22).
 *
 * One lane per shift on the same 06:00 → 30:00 window as `day-strip.tsx` and
 * the roster grid's day header, so a night turn is one unbroken bar running
 * off the right-hand end rather than two pieces at opposite edges of a
 * midnight-to-midnight chart. That window is the reason this is written fresh
 * instead of lifted from the marketing lane strip, which is drawn 00 → 24.
 *
 * **Bars arrive as minutes from 06:00**, 0 to 1440, and are divided here.
 * Nothing is rounded on the wire, so `"30:00"` — a slot that ends at 06:00 the
 * next morning — converts exactly, and the drawing is correct at whatever
 * width the panel happens to have.
 *
 * Geometry is `ui.css`'s `.lanes` family and `03-dashboard.html`, including
 * one thing §5.22's table does not say: the **axis is the first row**, above
 * the lanes, and the vertical overlay lines start below it (`top: 30px`) so
 * they run through the bars and not through the hour labels. The now label
 * sits in the axis row it belongs to.
 *
 * Nothing animates — §1 rule 7 — so the now marker jumps once a minute, the
 * same clock the day strip reads.
 */

/** The window is 24 hours wide; every position on it is minutes ÷ 1440. */
const WINDOW_MINUTES = 1440;

/** 24:00 — the one hour on this scale that is a different day. */
const MIDNIGHT = 0.75;

/** The label gutter and the count gutter, which the overlay has to clear. */
const LABEL_GUTTER = 92;
const COUNT_GUTTER = 52;

/** `.lanes` padding: the overlay is inset to the content box, not the panel. */
const PAD_TOP = 18;
const PAD_BOTTOM = 14;
const PAD_X = 20;

/** The axis row the overlay's lines start below. */
const AXIS_HEIGHT = 30;

/** Where the now marker goes, and what it prints. */
export interface Now {
    position: number;
    time: string;
}

export interface Lane {
    id: string;
    name: string;
    /** `shifts.color`, so the bar, the roster chip and the legend agree. */
    slot: number;
    count: number;
    /** Minutes from 06:00. `label` carries the wall clock the bar prints. */
    bars: { from: number; to: number; label: string }[];
}

/** A percentage with enough places that a 27px cell lands where it should. */
function at(fraction: number): string {
    return `${(fraction * 100).toFixed(4)}%`;
}

/** Clamp a slot the server sent into the ramp rather than rendering nothing. */
function ramp(slot: number): string {
    return RAMP[Math.min(Math.max(Math.trunc(slot), 1), 8) as Slot];
}

/**
 * The hour ruler. Majors at 06, 12, 18, 24 and 30 carry a taller tick and a
 * label; the rest are minor ticks. The closing 30 is drawn at 100% and its
 * label centred there, which is why the note that explains it
 * (`06 next day`) is pulled back from the same edge.
 */
function Axis() {
    return (
        <div className="relative h-[30px] flex-1" aria-hidden>
            <span className="bg-edge-soft absolute inset-x-0 top-4 h-px" />
            {Array.from({ length: 25 }, (_, hour) => {
                const major = hour % 6 === 0;

                return (
                    <span
                        key={hour}
                        className={cn(
                            'absolute w-px',
                            major ? 'bg-muted-foreground top-1.5 h-2.5' : 'bg-tick top-[11px] h-[5px]',
                        )}
                        style={{ left: at(hour / 24) }}
                    />
                );
            })}
            {[0, 6, 12, 18, 24].map((hour) => (
                <span
                    key={hour}
                    className="text-muted-foreground absolute top-[18px] -translate-x-1/2 text-[11px] leading-[14px] font-semibold tabular-nums"
                    style={{ left: at(hour / 24) }}
                >
                    {String(6 + hour).padStart(2, '0')}
                </span>
            ))}
            {/*
              Dropped below `sm`, where it would run into the 24 label rather
              than explain the 30 beside it. It is an annotation on a scale the
              axis already states; the figcaption carries the same fact in words.
            */}
            <span className="text-muted-foreground absolute top-0 left-full hidden -translate-x-full pr-1.5 text-[11px] leading-[14px] whitespace-nowrap sm:block">
                06 next day
            </span>
        </div>
    );
}

export function LaneChart({ lanes, now }: { lanes: Lane[]; now: Now | null }) {
    return (
        <figure className="border-border rounded-lg border">
            {/*
              The section's own heading already names the subject and the
              moment, so this says the one thing it cannot: what the scale is.
              A reader arriving at the figure gets the window; a reader coming
              from the heading is not told the time twice.
            */}
            <figcaption className="sr-only">
                On duty by shift, on a day that runs 06:00 to 30:00 — 06:00 the next morning.
            </figcaption>
            <div className="relative px-5 pt-[18px] pb-3.5">
                <div className="flex items-center">
                    <span className="flex-none" style={{ width: LABEL_GUTTER }} />
                    <Axis />
                    <span className="flex-none" style={{ width: COUNT_GUTTER }} />
                </div>

                {lanes.map((lane) => (
                    <div key={lane.id} className="flex items-center">
                        <span
                            className="flex-none truncate pr-2 text-[13px] leading-8 font-medium"
                            style={{ width: LABEL_GUTTER }}
                        >
                            {lane.name}
                        </span>
                        {/*
                          The lane's own hairline is painted by a flat gradient at
                          50%, not by a border: a border sits at an edge, and this
                          line has to run through the middle of the track behind
                          the bars.
                        */}
                        <div
                            className="relative h-8 flex-1 bg-[linear-gradient(var(--rule),var(--rule))] bg-[length:100%_1px] bg-[position:0_50%] bg-no-repeat"
                            aria-hidden
                        >
                            {lane.bars.map((bar) => (
                                <span
                                    key={`${bar.from}-${bar.to}`}
                                    className={cn(
                                        'absolute top-1 flex h-6 items-center overflow-hidden rounded-md border px-[7px] text-[11px] leading-[14px] font-semibold tabular-nums',
                                        ramp(lane.slot),
                                    )}
                                    style={{
                                        left: at(bar.from / WINDOW_MINUTES),
                                        width: at((bar.to - bar.from) / WINDOW_MINUTES),
                                    }}
                                >
                                    {bar.label}
                                </span>
                            ))}
                        </div>
                        <span
                            className="flex-none text-right text-[13px] leading-8 font-semibold tabular-nums"
                            style={{ width: COUNT_GUTTER }}
                        >
                            {lane.count}
                        </span>
                    </div>
                ))}

                {/*
                  One overlay for every line that crosses lanes, inset to the
                  track's own gutters so a position on it is a position on the
                  scale. `pointer-events-none` because it sits over the bars.
                */}
                <div
                    className="pointer-events-none absolute"
                    aria-hidden
                    style={{
                        left: PAD_X + LABEL_GUTTER,
                        right: PAD_X + COUNT_GUTTER,
                        top: PAD_TOP,
                        bottom: PAD_BOTTOM,
                    }}
                >
                    <span
                        className="absolute bottom-0 w-px bg-[linear-gradient(var(--edge-soft)_0_3px,transparent_3px_6px)] bg-[length:1px_6px] bg-repeat-y"
                        style={{ top: AXIS_HEIGHT, left: at(MIDNIGHT) }}
                    />
                    {now !== null && (
                        <>
                            <span
                                className="bg-primary absolute bottom-0 w-px"
                                style={{ top: AXIS_HEIGHT, left: at(now.position) }}
                            />
                            <span
                                className="bg-acc-soft text-acc-text absolute top-px ml-[7px] inline-flex h-[18px] items-center rounded-full px-1.5 text-[11px] leading-[14px] font-semibold whitespace-nowrap"
                                style={{ left: at(now.position) }}
                            >
                                {now.time}
                            </span>
                        </>
                    )}
                </div>
            </div>

            {/*
              §5.22: a bar with its hours written inside is legible, but the
              lane, the hours and the count have to reach a screen reader as
              text — the track and everything absolutely positioned over it is
              aria-hidden, so this table is the whole reading.
            */}
            <table className="sr-only">
                <thead>
                    <tr>
                        <th scope="col">Shift</th>
                        <th scope="col">Hours</th>
                        <th scope="col">On duty</th>
                    </tr>
                </thead>
                <tbody>
                    {lanes.map((lane) => (
                        <tr key={lane.id}>
                            <th scope="row">{lane.name}</th>
                            <td>{lane.bars.map((bar) => bar.label).join(', ')}</td>
                            <td>{lane.count}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}

/**
 * The now marker's position, or null when it would be a lie.
 *
 * `duty` describes one day window, and a dashboard left open past 06:00 is
 * showing yesterday's — the server computed the lanes once. Drawing a line at
 * this morning's 09:00 through last night's roster is worse than drawing no
 * line, so the marker is dropped and the bars stand as the record of a day
 * that has closed.
 */
export function useNowOn(date: string): Now | null {
    const clock = useManilaClock();

    return clock.date === date ? { position: clock.position, time: clock.time } : null;
}

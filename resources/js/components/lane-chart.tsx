import { useManilaClock } from '@/hooks/use-manila-clock';
import { cn } from '@/lib/utils';
import { RAMP, type Slot } from './shift-chip';

const WINDOW_MINUTES = 1440;

const MIDNIGHT = 0.75;

const LABEL_GUTTER = 92;
const COUNT_GUTTER = 52;

const PAD_TOP = 18;
const PAD_BOTTOM = 14;
const PAD_X = 20;

const AXIS_HEIGHT = 30;

export interface Now {
    position: number;
    time: string;
}

export interface Lane {
    id: string;
    name: string;
    slot: number;
    count: number;
    bars: { from: number; to: number; label: string }[];
}

function at(fraction: number): string {
    return `${(fraction * 100).toFixed(4)}%`;
}

function ramp(slot: number): string {
    return RAMP[Math.min(Math.max(Math.trunc(slot), 1), 8) as Slot];
}

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
            <span className="text-muted-foreground absolute top-0 left-full hidden -translate-x-full pr-1.5 text-[11px] leading-[14px] whitespace-nowrap sm:block">
                06 next day
            </span>
        </div>
    );
}

export function LaneChart({ lanes, now }: { lanes: Lane[]; now: Now | null }) {
    return (
        <figure className="border-border rounded-lg border">
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

export function useNowOn(date: string): Now | null {
    const clock = useManilaClock();

    return clock.date === date ? { position: clock.position, time: clock.time } : null;
}

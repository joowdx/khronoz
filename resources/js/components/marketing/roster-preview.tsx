import { AVATAR, OFF_HATCH, RAMP, ShiftChip, type Slot, type Tint } from '@/components/marketing/shift-chip';
import { cn } from '@/lib/utils';

type Turn = 'M' | 'A' | 'N' | 'S' | 'O';

const CYCLE: Turn[] = [
    'M',
    'M',
    'M',
    'M',
    'M',
    'O',
    'O',
    'A',
    'A',
    'A',
    'A',
    'A',
    'O',
    'O',
    'N',
    'N',
    'N',
    'N',
    'N',
    'O',
    'O',
];

const SHIFT: Record<Exclude<Turn, 'O'>, { slot: Slot; letter: string; hours: string }> = {
    M: { slot: 'c2', letter: 'M', hours: '06:00 to 14:00' },
    A: { slot: 'c5', letter: 'A', hours: '14:00 to 22:00' },
    N: { slot: 'c8', letter: 'N', hours: '22:00 to 06:00 the next day' },
    S: { slot: 'c6', letter: 'S', hours: '08:00 to 17:00' },
};

const WEEKDAYS = [
    'T',
    'W',
    'T',
    'F',
    'S',
    'S',
    'M',
    'T',
    'W',
    'T',
    'F',
    'S',
    'S',
    'M',
    'T',
    'W',
    'T',
    'F',
    'S',
    'S',
    'M',
];
const WEEKEND = [4, 5, 11, 12, 18, 19];
const TODAY = 14;
const DAY_W = 27;

const rotation = (anchor: number): Turn[] => Array.from({ length: 21 }, (_, day) => CYCLE[(anchor + day) % 21]!);

const ROWS: { name: string; number: string; initials: string; tint: Tint; schedule: string; turns: Turn[] }[] = [
    { name: 'Bautista, M.', number: '204-118', initials: 'BM', tint: 'a3', schedule: 'Rotation', turns: rotation(0) },
    { name: 'Cruz, J. P.', number: '204-233', initials: 'CJ', tint: 'a5', schedule: 'Rotation', turns: rotation(14) },
    {
        name: 'Delos Santos, A.',
        number: '204-297',
        initials: 'DS',
        tint: 'a8',
        schedule: 'Rotation',
        turns: rotation(7),
    },
    {
        name: 'Reyes, K.',
        number: '201-045',
        initials: 'RK',
        tint: 'a6',
        schedule: 'Standard week',
        turns: Array.from({ length: 21 }, (_, day) => (WEEKEND.includes(day) ? 'O' : 'S')),
    },
];

function nightRuns(turns: Turn[]): { start: number; nights: number }[] {
    const runs: { start: number; nights: number }[] = [];

    for (let day = 0; day < turns.length; day++) {
        if (turns[day] !== 'N') {
            continue;
        }

        const start = day;

        while (day < turns.length && turns[day] === 'N') {
            day++;
        }

        runs.push({ start, nights: day - start });
    }

    return runs;
}

const HEAD_CELL =
    'bg-background flex flex-none items-end pb-[7px] text-xs leading-4 font-semibold text-muted-foreground';
const FROZEN = 'sticky z-[22] px-3';
const TOTALS = 'flex min-w-[68px] flex-1 justify-end px-2.5';

function RosterGrid() {
    return (
        <div aria-hidden="true" className="relative overflow-auto [&_b]:not-italic [&_em]:not-italic [&_i]:not-italic">
            <div className="w-max min-w-[787px] md:min-w-[951px]">
                <div className="bg-background sticky top-0 z-20 flex h-[38px]">
                    <div className={cn(HEAD_CELL, FROZEN, 'left-0 w-[152px] md:w-[200px]')}>Employee</div>
                    <div className={cn(HEAD_CELL, FROZEN, 'left-[200px] hidden w-[120px] border-r md:flex')}>
                        Schedule
                    </div>
                    {WEEKDAYS.map((weekday, day) => (
                        <div
                            key={day}
                            className={cn(
                                'bg-background relative flex w-[27px] flex-none flex-col items-center justify-end gap-px pb-[5px]',
                                WEEKEND.includes(day) && 'bg-weekend',
                            )}
                        >
                            <em className="text-muted-foreground text-[11px] leading-[13px] font-medium">{weekday}</em>
                            {day === TODAY ? (
                                <b className="bg-primary text-primary-foreground -mb-0.5 flex size-5 items-center justify-center rounded-full text-xs leading-4 font-semibold tabular-nums">
                                    {day + 1}
                                </b>
                            ) : (
                                <b className="text-xs leading-4 font-semibold tabular-nums">{day + 1}</b>
                            )}
                        </div>
                    ))}
                    <div className={cn(HEAD_CELL, TOTALS)}>Hours</div>
                </div>

                <div className="relative border-t">
                    <div className="pointer-events-none absolute inset-y-0 right-0 left-[152px] md:left-[320px]">
                        {WEEKEND.map((day) => (
                            <i
                                key={day}
                                style={{ left: day * DAY_W }}
                                className="bg-weekend absolute inset-y-0 z-0 w-[27px]"
                            />
                        ))}
                        <span
                            style={{ left: TODAY * DAY_W + 13 }}
                            className="bg-primary absolute inset-y-0 z-[6] w-px"
                        />
                    </div>

                    {ROWS.map((row) => {
                        const runs = nightRuns(row.turns);
                        const nights = new Set(
                            runs.flatMap(({ start, nights }) => Array.from({ length: nights }, (_, i) => start + i)),
                        );

                        return (
                            <div
                                key={row.number}
                                className="border-rule group hover:bg-row-hover relative z-[1] flex h-[34px] border-b"
                            >
                                <div className="bg-background group-hover:bg-row-hover sticky left-0 z-[4] flex w-[152px] flex-none items-center gap-2 pr-2.5 pl-3 md:w-[200px]">
                                    <span
                                        className={cn(
                                            'inline-flex size-6 flex-none items-center justify-center rounded-full text-[10px] leading-[13px] font-semibold tracking-[0.01em]',
                                            AVATAR[row.tint],
                                        )}
                                        aria-hidden="true"
                                    >
                                        {row.initials}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-[13px] leading-[17px] font-medium">
                                        {row.name}
                                    </span>
                                    <span className="text-muted-foreground hidden text-[11px] leading-[14px] tabular-nums md:block">
                                        {row.number}
                                    </span>
                                </div>
                                <div className="bg-background group-hover:bg-row-hover text-muted-foreground sticky left-[200px] z-[4] hidden w-[120px] flex-none items-center overflow-hidden border-r px-3 text-xs leading-4 whitespace-nowrap md:flex">
                                    {row.schedule}
                                </div>

                                <div className="relative flex flex-none">
                                    {row.turns.map((turn, day) => (
                                        <div
                                            key={day}
                                            className={cn(
                                                'flex w-[27px] flex-none items-center justify-center',
                                                turn === 'O' && OFF_HATCH,
                                            )}
                                        >
                                            {turn !== 'O' && !nights.has(day) && (
                                                <ShiftChip slot={SHIFT[turn].slot} className="h-[22px] w-[21px]">
                                                    {SHIFT[turn].letter}
                                                </ShiftChip>
                                            )}
                                        </div>
                                    ))}
                                    {runs.map((run) => (
                                        <span
                                            key={run.start}
                                            style={{ left: run.start * DAY_W + 12, width: run.nights * DAY_W + 3 }}
                                            className={cn(
                                                'absolute top-1.5 z-[5] flex h-[22px] items-center rounded-md border pl-[5px] text-[11px] leading-[14px] font-semibold',
                                                RAMP.c8,
                                            )}
                                        >
                                            22:00&ndash;06:00
                                            <span className="ml-[7px] font-medium">&#8314;&#185;</span>
                                        </span>
                                    ))}
                                </div>

                                <div
                                    className={cn(
                                        TOTALS,
                                        'text-muted-foreground items-center text-xs leading-4 font-medium tabular-nums',
                                    )}
                                >
                                    120:00
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

const LEGEND_ITEM = 'text-muted-foreground flex items-center gap-[7px] text-xs leading-4';

function Legend() {
    return (
        <div className="flex flex-wrap items-center gap-3 border-t px-3.5 py-[11px] md:gap-[18px] md:px-5 md:py-[13px]">
            <span className={LEGEND_ITEM}>
                <ShiftChip slot="c2" className="h-5 w-[22px]">
                    M
                </ShiftChip>
                Morning, 06:00 to 14:00
            </span>
            <span className={LEGEND_ITEM}>
                <ShiftChip slot="c5" className="h-5 w-[22px]">
                    A
                </ShiftChip>
                Afternoon, 14:00 to 22:00
            </span>
            <span className={LEGEND_ITEM}>
                <span className={cn('h-3.5 w-[30px] flex-none rounded-md border', RAMP.c8)} aria-hidden="true" />
                Night, 22:00 to 06:00 the next day
            </span>
            <span className={LEGEND_ITEM}>
                <span
                    className={cn('border-off-hatch h-5 w-[22px] flex-none rounded-md border', OFF_HATCH)}
                    aria-hidden="true"
                />
                Off
            </span>
            <span className={LEGEND_ITEM}>
                <ShiftChip slot="c6" className="h-5 w-[22px]">
                    S
                </ShiftChip>
                Standard, 08:00 to 17:00
            </span>
        </div>
    );
}

const pct = (hours: number) => (hours / 24) * 100;

const LANES: { name: string; slot: Slot; bars: { left: number; width: number; label: string }[]; count: number }[] = [
    { name: 'Morning', slot: 'c2', bars: [{ left: pct(6), width: pct(8), label: '06:00–14:00' }], count: 12 },
    { name: 'Afternoon', slot: 'c5', bars: [{ left: pct(14), width: pct(8), label: '14:00–22:00' }], count: 11 },
    {
        name: 'Night',
        slot: 'c8',
        bars: [
            { left: 0, width: pct(6), label: '…06:00' },
            { left: pct(22), width: pct(2), label: '22:00' },
        ],
        count: 12,
    },
];

const LANE_LABEL = 'w-16 flex-none text-xs leading-8 font-medium md:w-[92px] md:text-[13px]';
const LANE_COUNT =
    'w-[34px] flex-none text-right text-xs leading-8 font-semibold tabular-nums md:w-[52px] md:text-[13px]';

function DutyStrip() {
    return (
        <div aria-hidden="true" className="border-t">
            <div className="flex items-baseline gap-3 px-3.5 pt-[11px] md:px-5 md:pt-[13px]">
                <span className="text-[13px] leading-[18px] font-semibold">On duty, 15 September</span>
                <span className="flex-1" />
                <span className="text-muted-foreground text-xs leading-4">Employees</span>
            </div>

            <div className="relative">
                <div className="px-3.5 pt-6 pb-2 md:px-5 md:pb-2.5">
                    {LANES.map((lane) => (
                        <div key={lane.name} className="flex items-center">
                            <span className={LANE_LABEL}>{lane.name}</span>
                            <span className="relative h-8 flex-1 bg-[linear-gradient(var(--rule),var(--rule))] bg-[length:100%_1px] bg-[position:0_50%] bg-no-repeat">
                                {lane.bars.map((bar) => (
                                    <span
                                        key={bar.left}
                                        style={{ left: `${bar.left}%`, width: `${bar.width}%` }}
                                        className={cn(
                                            'absolute top-1 flex h-6 items-center overflow-hidden rounded-md border px-[7px] text-[11px] leading-[14px] font-semibold tabular-nums',
                                            RAMP[lane.slot],
                                        )}
                                    >
                                        {bar.label}
                                    </span>
                                ))}
                            </span>
                            <span className={LANE_COUNT}>{lane.count}</span>
                        </div>
                    ))}

                    <div className="flex items-center">
                        <span className={LANE_LABEL} />
                        <span className="relative h-[30px] flex-1">
                            <span className="bg-edge-soft absolute inset-x-0 top-4 h-px" />
                            {Array.from({ length: 25 }, (_, hour) => (
                                <span
                                    key={hour}
                                    style={{ left: `${pct(hour)}%` }}
                                    className={
                                        hour % 6 === 0
                                            ? 'bg-muted-foreground absolute top-1.5 h-2.5 w-px'
                                            : 'bg-tick absolute top-[11px] h-[5px] w-px'
                                    }
                                />
                            ))}
                            {[0, 6, 12, 18].map((hour) => (
                                <span
                                    key={hour}
                                    style={{ left: `${pct(hour)}%` }}
                                    className="text-muted-foreground absolute top-[18px] -translate-x-1/2 text-[11px] leading-[14px] font-semibold tabular-nums"
                                >
                                    {String(hour).padStart(2, '0')}:00
                                </span>
                            ))}
                            <span className="text-muted-foreground absolute top-0 left-full -translate-x-full pr-1.5 text-[11px] leading-[14px] whitespace-nowrap">
                                24:00, then the next day
                            </span>
                        </span>
                        <span className={LANE_COUNT} />
                    </div>
                </div>

                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute top-6 right-12 bottom-[22px] left-[78px] z-[3] md:right-[72px] md:bottom-6 md:left-[112px]"
                >
                    <span style={{ left: `${pct(9.667)}%` }} className="bg-primary absolute inset-y-0 w-px" />
                    <span
                        style={{ left: `${pct(9.667)}%` }}
                        className="bg-acc-soft text-acc-text absolute -top-[22px] ml-[7px] inline-flex h-[18px] items-center rounded-full px-1.5 text-[11px] leading-[14px] font-semibold whitespace-nowrap tabular-nums"
                    >
                        09:40
                    </span>
                </div>
            </div>
        </div>
    );
}

export function RosterPreview() {
    return (
        <figure className="bg-background mt-7 overflow-hidden rounded-xl border md:mt-12">
            <figcaption className="flex flex-wrap items-baseline gap-x-3.5 gap-y-0.5 border-b px-3.5 pt-[13px] pb-3 md:flex-nowrap md:px-5 md:pt-[15px] md:pb-[13px]">
                <span className="text-[22px] leading-7 font-bold tracking-[-0.01em] tabular-nums">September 2026</span>
                <span className="flex-1" />
                <span className="text-muted-foreground text-[13px] leading-[18px] tabular-nums">
                    Nursing service, 1 to 21 September
                </span>
            </figcaption>

            <p className="sr-only">
                A three-week roster for four nurses. Three of them share one 21-day rotation, anchored seven days apart,
                so the morning, afternoon and night turns move through the team; the fourth works a standard week. Each
                night run is drawn as one band from 22:00 to 06:00 the next day, and every row totals 120 hours. Under
                it, the same day on a 24-hour scale: 12 employees on the morning turn, 11 on the afternoon and 12 on the
                night.
            </p>

            <RosterGrid />
            <Legend />
            <DutyStrip />
        </figure>
    );
}

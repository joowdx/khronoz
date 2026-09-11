import { OFF_HATCH, RAMP, type Slot } from '@/components/shift-chip';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

/**
 * The roster grid — employees down, days across, one month at a time.
 *
 * Geometry is docs/design/mockups/ui.css's `.rg-*` family and §4.3/§5.23 of
 * 08-interface.md, translated to utilities: day column 27, data row 34, group
 * row 28, head 38, frozen columns 200 + 120, chip 21 x 22, band 22 high opening
 * 12px into its own column and closing 15px into the next.
 *
 * The z-ladder is §4.4's and is page-wide, so these numbers are not local
 * choices: wash 0, row 1, group row 2, band 5, now-line 6, head 20, frozen head
 * cell 22.
 *
 * **One deliberate deviation: the frozen row cells sit at 7, not §4.4's 4.**
 * The artboard is 1440 wide with the sidebar collapsed to a 64px rail, which
 * makes the grid exactly as wide as its column, so it never scrolls sideways
 * and a band never travels far enough left to reach the frozen columns. Here it
 * does, and at 4 the band and the now-line painted straight over the employee
 * names — MEASURED, scrolled 400px in an 800px viewport. The ladder above the
 * frozen cell is what it orders *within the days container*; the frozen columns
 * are not in that contest, they are the thing the contest scrolls underneath.
 * If the rail lands and the grid stops scrolling, this can go back to 4 and
 * nothing will look different.
 *
 * Four traps from §9.4 are load-bearing here and each is dodged deliberately:
 *
 * 1. **Frozen columns need an opaque ground at rest *and* under row hover.**
 *    The default is transparent, so the body scrolls under them; and a frozen
 *    cell that only sets a rest background lets the row's hover tint slide
 *    underneath it. Hence `bg-background` plus `group-hover:bg-row-hover`,
 *    with `group` on the row itself.
 * 2. **A sticky head needs its background on the cell, not the container** —
 *    a background on the strip does not paint under a sticky child, and rows
 *    show through.
 * 3. **Row dividers are `--rule`, the head rule is `--line`.** Two different
 *    greys; Tailwind's `border-*` default is neither.
 * 4. The scroller must not be wrapped in an `overflow: hidden` panel, or it
 *    becomes the sticky scrollport and the head silently stops sticking.
 */
const DAY = 27;
const FROZEN = 320;
const TOTAL = 60;

export interface RosterDay {
    date: string;
    weekday: string;
    day: number;
    weekend: boolean;
    holiday: string | null;
    suspension: string | null;
    today: boolean;
}

export type RosterCell =
    | { kind: 'shift'; slot: Slot; letter: string; name: string; hours: string }
    | { kind: 'off'; name: string }
    | { kind: 'remote'; name: string }
    | { kind: 'none' };

export interface RosterBand {
    start: number;
    days: number;
    letter: string;
    hours: string;
    /** The run reaches back past the first column; it opens off the left edge. */
    clipped: boolean;
}

export interface RosterRow {
    id: string;
    name: string;
    number: string;
    schedule: string | null;
    cells: RosterCell[];
    bands: RosterBand[];
    totals: { duty: number; nights: number; off: number };
}

export interface RosterGroup {
    name: string;
    meta: string;
    rows: RosterRow[];
}

/** A cell's accessible name — the grid is read one day at a time. */
function describe(day: RosterDay, cell: RosterCell): string {
    const when = `${day.day} ${day.date}`;

    switch (cell.kind) {
        case 'shift':
            return `${when}, ${cell.name}, ${cell.hours}`;
        case 'off':
            return `${when}, ${cell.name}, rest day`;
        case 'remote':
            return `${when}, ${cell.name}, remote`;
        default:
            return `${when}, no roster`;
    }
}

export function RosterGrid({
    days,
    groups,
    nowIndex,
    legend,
    note,
    selected,
    onToggle,
}: {
    days: RosterDay[];
    groups: RosterGroup[];
    nowIndex: number | null;
    legend: { name: string; slot: Slot; letter: string; hours: string; kind: string; night: boolean }[];
    note: string | null;
    /** Absent when the viewer cannot assign — then no checkbox is drawn at all. */
    selected?: string[];
    onToggle?: (id: string) => void;
}) {
    const width = FROZEN + days.length * DAY + TOTAL * 3;

    return (
        <div>
            <div className="relative max-h-[calc(100svh-260px)] overflow-auto" role="grid" aria-label="Roster">
                <div style={{ width }}>
                    {/* Head. Sticky at the top; its two frozen cells sticky sideways too. */}
                    <div className="bg-background sticky top-0 z-20 flex h-[38px]" role="row">
                        <div
                            role="columnheader"
                            className="bg-background text-muted-foreground sticky left-0 z-[22] flex w-[200px] flex-none items-end px-3 pb-[7px] text-xs leading-4 font-semibold"
                        >
                            Employee
                        </div>
                        <div
                            role="columnheader"
                            className="bg-background text-muted-foreground border-border sticky left-[200px] z-[22] flex w-[120px] flex-none items-end border-r px-3 pb-[7px] text-xs leading-4 font-semibold"
                        >
                            Schedule
                        </div>
                        {days.map((day) => (
                            <div
                                key={day.date}
                                role="columnheader"
                                title={day.holiday ?? day.suspension ?? undefined}
                                className={cn(
                                    'bg-background relative flex w-[27px] flex-none flex-col items-center justify-end gap-px pb-[5px]',
                                    day.weekend && 'bg-weekend',
                                    day.suspension && 'bg-attention-soft',
                                )}
                            >
                                <em
                                    className={cn(
                                        'text-muted-foreground text-[11px] leading-[13px] font-medium not-italic',
                                        day.suspension && 'text-attention',
                                    )}
                                >
                                    {day.weekday}
                                </em>
                                <b
                                    className={cn(
                                        'text-xs leading-4 font-semibold tabular-nums',
                                        day.suspension && 'text-attention',
                                        day.today &&
                                            'bg-primary text-primary-foreground -mb-0.5 flex h-5 w-5 items-center justify-center rounded-full',
                                    )}
                                >
                                    {day.day}
                                </b>
                            </div>
                        ))}
                        {['Duty', 'Nights', 'Off'].map((label) => (
                            <div
                                key={label}
                                role="columnheader"
                                style={{ width: TOTAL }}
                                className="bg-background text-muted-foreground flex flex-none items-end justify-end px-2.5 pb-[7px] text-xs leading-4 font-semibold"
                            >
                                {label}
                            </div>
                        ))}
                    </div>

                    <div className="border-border relative border-t">
                        {/*
                          One wash layer rather than a tint per cell, so the row's
                          own hover paints above it. Pointer-events off, or it
                          would eat the hover it sits under.
                        */}
                        <div aria-hidden className="pointer-events-none absolute inset-0 z-0">
                            {days.map((day, index) =>
                                day.weekend || day.suspension ? (
                                    <i
                                        key={day.date}
                                        style={{ left: FROZEN + index * DAY, width: DAY }}
                                        className={cn(
                                            'absolute top-0 bottom-0',
                                            day.suspension ? 'bg-attention-soft' : 'bg-weekend',
                                        )}
                                    />
                                ) : null,
                            )}
                        </div>

                        {nowIndex !== null && (
                            <div
                                aria-hidden
                                style={{ left: FROZEN + nowIndex * DAY }}
                                className="bg-primary absolute top-0 bottom-0 z-[6] w-px"
                            />
                        )}

                        {groups.map((group) => (
                            <div key={group.name} role="rowgroup">
                                <div className="border-border bg-row-hover relative z-[2] flex h-[28px] items-center border-b">
                                    <span className="bg-row-hover sticky left-0 z-[7] flex items-baseline gap-[10px] px-3 whitespace-nowrap">
                                        <b className="text-xs leading-4 font-semibold">{group.name}</b>
                                        <b className="text-muted-foreground text-xs leading-4 font-normal">
                                            {group.meta}
                                        </b>
                                    </span>
                                </div>
                                {group.rows.map((row) => (
                                    <Row
                                        key={row.id}
                                        row={row}
                                        days={days}
                                        selected={selected?.includes(row.id) ?? false}
                                        onToggle={onToggle}
                                    />
                                ))}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <Legend legend={legend} note={note} />
        </div>
    );
}

/**
 * Selection sits inside the frozen employee cell rather than in a column of its
 * own. The artboard draws no checkbox column, and adding one would cost 27px of
 * every row and a fourth sticky cell — but a click target with no control is
 * not operable by keyboard, so it is a real checkbox in the space already there.
 */
function Row({
    row,
    days,
    selected,
    onToggle,
}: {
    row: RosterRow;
    days: RosterDay[];
    selected: boolean;
    onToggle?: (id: string) => void;
}) {
    const ground = selected ? 'bg-acc-tint' : 'bg-background group-hover:bg-row-hover';

    return (
        <div
            role="row"
            aria-selected={onToggle ? selected : undefined}
            className={cn(
                'group border-rule relative z-[1] flex h-[34px] border-b',
                selected ? 'bg-acc-tint' : 'hover:bg-[var(--row-hover)]',
            )}
        >
            <div
                role="rowheader"
                className={cn('sticky left-0 z-[7] flex w-[200px] flex-none items-center gap-2 py-0 pr-2.5 pl-3', ground)}
            >
                {onToggle && (
                    <Checkbox
                        checked={selected}
                        onCheckedChange={() => onToggle(row.id)}
                        aria-label={`Select ${row.name}`}
                        className="flex-none"
                    />
                )}
                <span className="min-w-0 flex-1 truncate text-[13px] leading-[17px] font-medium">{row.name}</span>
                <span className="text-muted-foreground text-[11px] leading-[14px] tabular-nums">{row.number}</span>
            </div>
            <div
                role="gridcell"
                className={cn(
                    'border-border text-muted-foreground sticky left-[200px] z-[7] flex w-[120px] flex-none items-center overflow-hidden border-r px-3 text-xs leading-4 whitespace-nowrap',
                    ground,
                )}
            >
                {row.schedule}
            </div>

            <div className="relative flex flex-none">
                {row.cells.map((cell, index) => (
                    <div
                        key={days[index]?.date ?? index}
                        role="gridcell"
                        aria-label={days[index] ? describe(days[index], cell) : undefined}
                        className={cn(
                            'flex w-[27px] flex-none items-center justify-center',
                            cell.kind === 'off' && cn('border-off-hatch', OFF_HATCH),
                        )}
                    >
                        {cell.kind === 'shift' && (
                            <b
                                className={cn(
                                    'flex h-[22px] w-[21px] items-center justify-center rounded-md border text-[11px] leading-[14px] font-semibold',
                                    RAMP[cell.slot],
                                )}
                            >
                                {cell.letter}
                            </b>
                        )}
                        {cell.kind === 'remote' && (
                            <span className="border-input text-muted-foreground flex h-[22px] w-[21px] items-center justify-center rounded-md border border-dashed text-[11px] leading-[14px] font-semibold">
                                R
                            </span>
                        )}
                    </div>
                ))}

                {/*
                  One band per run, never one per day: a night shift is a single
                  stretch of work that happens to be listed under the day it
                  began, and drawing it per column would say it stopped and
                  started again at midnight. It opens 12px into its own column
                  and closes 15px into the next, which is what makes 22:00–06:00
                  legible as spilling forward. A run already under way when the
                  month opened is clipped to the left edge instead.
                */}
                {row.bands.map((band) => (
                    <span
                        key={band.start}
                        aria-hidden
                        style={
                            band.clipped
                                ? { left: -15, width: band.days * DAY + 3 + 12 + 15, paddingLeft: 20 }
                                : { left: band.start * DAY + 12, width: band.days * DAY + 3, paddingLeft: 5 }
                        }
                        className="border-c8-edge bg-c8-fill text-c8-text absolute top-[6px] z-[5] flex h-[22px] items-center overflow-hidden rounded-md border text-[11px] leading-[14px] font-semibold whitespace-nowrap"
                    >
                        <b className="font-bold">{band.letter}</b>
                        <span className="ml-[7px] font-medium">{band.hours}</span>
                    </span>
                ))}
            </div>

            {[row.totals.duty, row.totals.nights, row.totals.off].map((value, index) => (
                <div
                    key={index}
                    role="gridcell"
                    style={{ width: TOTAL }}
                    className="text-muted-foreground flex flex-none items-center justify-end px-2.5 text-xs leading-4 font-medium tabular-nums"
                >
                    {value}
                </div>
            ))}
        </div>
    );
}

function Legend({
    legend,
    note,
}: {
    legend: { name: string; slot: Slot; letter: string; hours: string; kind: string; night: boolean }[];
    note: string | null;
}) {
    return (
        <div className="border-border flex flex-wrap items-center gap-[18px] border-t px-5 py-[13px]">
            {legend.map((item) => (
                <div key={item.name} className="text-muted-foreground flex items-center gap-[7px] text-xs leading-4">
                    {item.kind === 'working' && (
                        <b
                            className={cn(
                                'flex h-5 w-[22px] flex-none items-center justify-center rounded-md border text-[11px] leading-[14px] font-semibold',
                                RAMP[item.slot],
                            )}
                        >
                            {item.letter}
                        </b>
                    )}
                    {item.kind === 'off' && (
                        <span className={cn('border-off-hatch h-5 w-[22px] flex-none rounded-md border', OFF_HATCH)} />
                    )}
                    {item.kind === 'remote' && (
                        <span className="border-input h-5 w-[22px] flex-none rounded-md border border-dashed" />
                    )}
                    <span>
                        {item.name}
                        {item.hours !== '' && `, ${item.hours}`}
                    </span>
                </div>
            ))}
            {note !== null && (
                <div className="text-attention ml-auto flex items-center gap-[7px] text-xs leading-4 font-medium">
                    {note}
                </div>
            )}
        </div>
    );
}

import { OFF_HATCH, ShiftChip, type Slot } from '@/components/marketing/shift-chip';
import { cn } from '@/lib/utils';


const CYCLE: (Slot | null)[] = [
    'c2',
    'c2',
    'c2',
    'c2',
    'c2',
    null,
    null,
    'c5',
    'c5',
    'c5',
    'c5',
    'c5',
    null,
    null,
    'c8',
    'c8',
    'c8',
    'c8',
    'c8',
    null,
    null,
];

const LETTER: Record<Slot, string> = { c2: 'M', c5: 'A', c6: 'S', c8: 'N' };

const LEGEND_ITEM = 'text-muted-foreground flex items-center gap-[7px] text-xs leading-4';

export function RotationStrip() {
    return (
        <div className="overflow-hidden rounded-xl border">
            <div className="flex items-baseline gap-2.5 border-b px-4 pt-3 pb-[11px]">
                <span className="text-sm leading-5 font-semibold">Rotation</span>
                <span className="text-muted-foreground text-xs leading-4 tabular-nums">21 days</span>
                <span className="flex-1" />
                <span className="text-muted-foreground text-xs leading-4 tabular-nums">
                    3 teams, anchors 7 days apart
                </span>
            </div>

            <div className="overflow-auto p-4">
                <div className="w-max" aria-hidden="true">
                    <div className="flex">
                        {CYCLE.map((slot, day) => (
                            <span
                                key={day}
                                className={cn(
                                    'flex w-6 flex-none items-center justify-center',
                                    slot === null && cn('h-6', OFF_HATCH),
                                )}
                            >
                                {slot !== null && (
                                    <ShiftChip slot={slot} className="h-6 w-5">
                                        {LETTER[slot]}
                                    </ShiftChip>
                                )}
                            </span>
                        ))}
                    </div>
                    <div className="flex pt-1.5">
                        {CYCLE.map((_, day) => (
                            <span
                                key={day}
                                className="text-muted-foreground w-6 flex-none text-center text-[10px] leading-[14px] font-medium tabular-nums"
                            >
                                {day % 7 === 0 ? day : ' '}
                            </span>
                        ))}
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-[18px] border-t px-4 py-3">
                <span className={LEGEND_ITEM}>
                    <ShiftChip slot="c2" className="h-5 w-[22px]">
                        M
                    </ShiftChip>
                    Morning
                </span>
                <span className={LEGEND_ITEM}>
                    <ShiftChip slot="c5" className="h-5 w-[22px]">
                        A
                    </ShiftChip>
                    Afternoon
                </span>
                <span className={LEGEND_ITEM}>
                    <ShiftChip slot="c8" className="h-5 w-[22px]">
                        N
                    </ShiftChip>
                    Night
                </span>
                <span className={LEGEND_ITEM}>
                    <span
                        className={cn('border-off-hatch h-5 w-[22px] flex-none rounded-md border', OFF_HATCH)}
                        aria-hidden="true"
                    />
                    Off
                </span>
            </div>
        </div>
    );
}

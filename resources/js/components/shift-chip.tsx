import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A shift's colour comes from `shifts.color` — a smallint 1 to 8, stored and
 * never derived from the name or the id (04-scheduling.md rule 8,
 * 08-interface.md §7.2). So the slot is keyed by that number, not by a class
 * suffix: a new shift takes the lowest index its agency is not using and wraps
 * at 8, a platform copy carries its origin's index, and a timekeeper may change
 * it. More than eight shifts means a repeated colour, which is accepted — the
 * letter, the legend and the Schedule column disambiguate.
 *
 * The same index drives the roster chip, the lane bar, the sheet's cycle
 * preview and the legend swatch, so every surface reading a shift reads this.
 *
 * `Off` and `Remote` never take an index: one is a hatch, the other a dashed
 * box, and both are drawn from the helpers below rather than from the ramp.
 *
 * A near-copy of this token map lives in components/marketing/shift-chip.tsx.
 * That is deliberate. The marketing components are a frozen static fixture on
 * a public, server-rendered page, and importing an application component into
 * them would couple the public bundle to the internal one to save fifteen
 * lines of class strings.
 */
export type Slot = 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8;

export const RAMP: Record<Slot, string> = {
    1: 'bg-c1-fill border-c1-edge text-c1-text',
    2: 'bg-c2-fill border-c2-edge text-c2-text',
    3: 'bg-c3-fill border-c3-edge text-c3-text',
    4: 'bg-c4-fill border-c4-edge text-c4-text',
    5: 'bg-c5-fill border-c5-edge text-c5-text',
    6: 'bg-c6-fill border-c6-edge text-c6-text',
    7: 'bg-c7-fill border-c7-edge text-c7-text',
    8: 'bg-c8-fill border-c8-edge text-c8-text',
};

/** The eight slots in order, for a picker that marks which are already taken. */
export const SLOTS: readonly Slot[] = [1, 2, 3, 4, 5, 6, 7, 8];

/**
 * A rest day: `--off-fill` under a 45 degree hatch. This is the one gradient
 * the design allows, because it carries data rather than decorating (§1 rule 6).
 */
export const OFF_HATCH =
    'bg-off-fill [background-image:repeating-linear-gradient(45deg,var(--off-hatch)_0_1px,transparent_1px_5px)]';

/** 11/14/600 on one ramp slot, chip radius. The caller sets the size. */
export function ShiftChip({ slot, className, children }: { slot: Slot; className?: string; children: ReactNode }) {
    return (
        <span
            className={cn(
                'inline-flex flex-none items-center justify-center rounded-md border text-[11px] leading-[14px] font-semibold tabular-nums',
                RAMP[slot],
                className,
            )}
        >
            {children}
        </span>
    );
}

/**
 * A remote day expects no punches, so it is drawn as an outline rather than a
 * fill — §5.23's 21x22 box, radius 6, dashed `--edge`, muted letter. It takes
 * no ramp slot: there is nothing to tell apart from another remote day.
 */
export function RemoteBox({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'border-edge text-muted-foreground inline-flex flex-none items-center justify-center rounded-md border border-dashed text-[11px] leading-[14px] font-semibold',
                className,
            )}
        >
            R
        </span>
    );
}

/** A rest day cell: the hatch with the off edge, sized by the caller. */
export function OffBox({ className }: { className?: string }) {
    return <span aria-hidden className={cn('border-off-hatch flex-none rounded-md border', OFF_HATCH, className)} />;
}

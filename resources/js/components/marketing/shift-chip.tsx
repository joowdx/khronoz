import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The eight-slot chip ramp as Tailwind utilities. A shift's slot comes from
 * `shifts.color`, never from its name — docs/design/08-interface.md §7.2. The
 * seeded mapping in §7.1 puts Morning on slot 2, Afternoon on 5, Standard on 6
 * and Night on 8, which is also what the sign-in screen's preview draws.
 */
export const RAMP = {
    c2: 'bg-c2-fill border-c2-edge text-c2-text',
    c5: 'bg-c5-fill border-c5-edge text-c5-text',
    c6: 'bg-c6-fill border-c6-edge text-c6-text',
    c8: 'bg-c8-fill border-c8-edge text-c8-text',
} as const;

/** An avatar tint reads the same ramp, by name rather than by shift (§7.3). */
export const AVATAR = {
    a3: 'bg-c3-fill text-c3-text',
    a5: 'bg-c5-fill text-c5-text',
    a6: 'bg-c6-fill text-c6-text',
    a8: 'bg-c8-fill text-c8-text',
} as const;

export type Slot = keyof typeof RAMP;
export type Tint = keyof typeof AVATAR;

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

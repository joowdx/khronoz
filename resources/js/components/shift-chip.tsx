import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

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

export const SLOTS: readonly Slot[] = [1, 2, 3, 4, 5, 6, 7, 8];

export const OFF_HATCH =
    'bg-off-fill [background-image:repeating-linear-gradient(45deg,var(--off-hatch)_0_1px,transparent_1px_5px)]';

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

export function OffBox({ className }: { className?: string }) {
    return <span aria-hidden className={cn('border-off-hatch flex-none rounded-md border', OFF_HATCH, className)} />;
}

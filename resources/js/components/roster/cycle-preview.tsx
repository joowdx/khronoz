import { OFF_HATCH, RAMP, type Slot } from '@/components/shift-chip';
import { cn } from '@/lib/utils';

const MARK_EVERY = 7;

export interface CycleTurn {
    position: number;
    slot: Slot | null;
    name: string;
}

export function CyclePreview({ turns }: { turns: CycleTurn[] }) {
    if (turns.length === 0) {
        return null;
    }

    return (
        <div aria-hidden>
            <div className="mt-2 flex gap-0.5">
                {turns.map((turn) => (
                    <i
                        key={turn.position}
                        title={turn.name}
                        className={cn(
                            'h-[22px] flex-1 rounded-md border not-italic',
                            turn.slot === null ? cn('border-off-hatch', OFF_HATCH) : RAMP[turn.slot],
                        )}
                    />
                ))}
            </div>
            <div className="mt-1 flex gap-0.5">
                {turns.map((turn) => (
                    <span
                        key={turn.position}
                        className="text-muted-foreground flex-1 text-center text-[10px] leading-[13px] font-medium tabular-nums"
                    >
                        {turn.position === 0 || (turn.position + 1) % MARK_EVERY === 0 ? turn.position + 1 : ' '}
                    </span>
                ))}
            </div>
        </div>
    );
}

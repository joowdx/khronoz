import { OFF_HATCH, RAMP, type Slot } from '@/components/shift-chip';
import { cn } from '@/lib/utils';

/**
 * A schedule's cycle as a strip — one cell per turn, in the shift's own ramp
 * colour, rest turns hatched. Underneath, the position numbers at 1 and then
 * every seventh day, so a 21-day rotation reads as three weeks at a glance.
 *
 * The cells carry no letter. At `flex: 1` across a 420px sheet a 21-day cycle
 * gives each one about 18px, which is under the 21px the chip's 11px figure
 * needs — and the colour is the thing being previewed anyway. The artboard's
 * `.cycle i` chips are empty for the same reason.
 *
 * This geometry lives only in `06-roster-grid.html`'s own `<style>` block, not
 * in `ui.css`, so it is ported here rather than translated from a shared rule.
 */
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

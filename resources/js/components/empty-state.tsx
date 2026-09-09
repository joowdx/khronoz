import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * An empty screen is an invitation to act, not a placeholder. Left-aligned in
 * the content column with no dashed box — a dashed box says "something is
 * missing here"; the sentence already says that, and says what to do about it.
 *
 * Heading at 20/26/700, per ui.css's `.empty h2` and docs/design/08-interface.md
 * §5.20. The sentence should teach the model rather than apologise for the
 * absence, and the action repeats the one in the title bar.
 */
export function EmptyState({
    title,
    description,
    action,
    className,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div data-slot="empty-state" className={cn('max-w-[460px] py-12', className)}>
            <h2 className="text-xl leading-[26px] font-bold tracking-[-0.008em]">{title}</h2>
            {description && <p className="text-muted-foreground pt-2 pb-4 text-sm leading-5">{description}</p>}
            {action}
        </div>
    );
}

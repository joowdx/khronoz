import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

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

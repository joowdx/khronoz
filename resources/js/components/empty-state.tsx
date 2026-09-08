import type { ReactNode } from 'react';

export function EmptyState({
    title,
    description,
    action,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-md border border-dashed p-10 text-center">
            <h2 className="text-sm font-medium">{title}</h2>
            {description && <p className="text-muted-foreground text-sm">{description}</p>}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}

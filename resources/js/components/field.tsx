import { type ReactNode, useId } from 'react';
import { cn } from '@/lib/utils';

export function Field({
    label,
    error,
    hint,
    htmlFor,
    className,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    htmlFor?: string;
    className?: string;
    children: (control: { id: string; invalid: boolean | undefined; describedBy: string | undefined }) => ReactNode;
}) {
    const generated = useId();
    const id = htmlFor ?? generated;
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;

    const describedBy = [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div data-slot="field" className={cn('min-w-0', className)}>
            <div className="flex items-baseline justify-between gap-3 pb-1.5">
                <label htmlFor={id} className="text-[13px] leading-[18px] font-medium">
                    {label}
                </label>
                <p
                    id={errorId}
                    aria-live="polite"
                    className="text-destructive min-h-[18px] text-right text-[13px] leading-[18px] font-medium"
                >
                    {error || '\u200b'}
                </p>
            </div>
            {children({ id, invalid: error ? true : undefined, describedBy })}
            {hint && (
                <p id={hintId} className="text-muted-foreground pt-1.5 text-xs leading-4">
                    {hint}
                </p>
            )}
        </div>
    );
}

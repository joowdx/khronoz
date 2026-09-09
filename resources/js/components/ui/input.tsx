import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * A 36px text box on the canvas, bordered with --input (deliberately darker
 * than --border: a control's edge has to clear WCAG 1.4.11's 3:1, a panel's
 * hairline does not). The focus ring and its -1px offset come from app.css's
 * base layer; only the invalid recolouring is per component, and it has to be
 * a utility so it outranks that base rule.
 */
function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
    return (
        <input
            type={type}
            data-slot="input"
            className={cn(
                'border-input bg-background text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground h-9 w-full min-w-0 rounded-lg border px-3 text-sm leading-5 transition-[color,background-color,border-color]',
                'file:text-foreground file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium',
                'hover:border-foreground focus-visible:hover:border-ring',
                'aria-invalid:border-destructive aria-invalid:hover:border-destructive aria-invalid:focus-visible:border-destructive aria-invalid:focus-visible:outline-destructive',
                'disabled:bg-rule disabled:text-muted-foreground disabled:border-edge-soft disabled:pointer-events-none disabled:cursor-not-allowed',
                'read-only:bg-rule read-only:text-muted-foreground read-only:border-edge-soft read-only:hover:border-edge-soft',
                className,
            )}
            {...props}
        />
    );
}

export { Input };

import * as React from 'react';
import { cn } from '@/lib/utils';

function Textarea({ className, ...props }: React.ComponentProps<'textarea'>) {
    return (
        <textarea
            data-slot="textarea"
            className={cn(
                'border-input bg-background text-foreground placeholder:text-muted-foreground field-sizing-content flex min-h-20 w-full rounded-lg border px-3 py-2 text-sm leading-5 transition-[color,background-color,border-color]',
                'hover:border-foreground focus-visible:hover:border-ring',
                'aria-invalid:border-destructive aria-invalid:hover:border-destructive aria-invalid:focus-visible:border-destructive aria-invalid:focus-visible:outline-destructive',
                'disabled:bg-rule disabled:text-muted-foreground disabled:border-edge-soft disabled:cursor-not-allowed',
                className,
            )}
            {...props}
        />
    );
}

export { Textarea };

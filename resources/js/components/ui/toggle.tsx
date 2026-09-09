import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';
import { Toggle as TogglePrimitive } from 'radix-ui';

/**
 * A standalone two-state control. The segmented control is a different thing
 * with its own geometry — see components/ui/toggle-group.
 */
const toggleVariants = cva(
    "inline-flex items-center justify-center gap-[7px] rounded-lg text-[13px] leading-[18px] font-medium whitespace-nowrap transition-[color,background-color,border-color] disabled:pointer-events-none disabled:opacity-45 data-[state=on]:bg-acc-soft data-[state=on]:text-acc-text data-[state=on]:font-semibold [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
    {
        variants: {
            variant: {
                default: 'bg-transparent hover:bg-row-hover',
                outline: 'border-input bg-background border hover:bg-row-hover',
            },
            size: {
                sm: 'h-8 min-w-8 px-2',
                default: 'h-9 min-w-9 px-2.5',
                lg: 'h-11 min-w-11 px-3',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

function Toggle({
    className,
    variant,
    size,
    ...props
}: React.ComponentProps<typeof TogglePrimitive.Root> & VariantProps<typeof toggleVariants>) {
    return (
        <TogglePrimitive.Root
            data-slot="toggle"
            className={cn(toggleVariants({ variant, size, className }))}
            {...props}
        />
    );
}

export { Toggle, toggleVariants };

import * as React from 'react';
import { cn } from '@/lib/utils';
import { ToggleGroup as ToggleGroupPrimitive } from 'radix-ui';

/**
 * The segmented control (.seg): a 32px bordered track holding 26px segments,
 * with the selected one on the accent tint at 600. It is the closest thing
 * shadcn generates to the design's control, so it *is* that control here —
 * the generated `variant`/`spacing` machinery is gone, because the design has
 * one segmented control, not a family of them.
 *
 * `full` stretches it to the container and shares the width evenly, which is
 * how the appearance switcher sits inside the user menu.
 */
function ToggleGroup({
    className,
    full = false,
    /**
     * Accepted and ignored. shadcn generates a `variant` here; the design has
     * one segmented control, so it is recorded as an attribute for styling
     * hooks rather than branching the geometry.
     */
    variant,
    children,
    ...props
}: React.ComponentProps<typeof ToggleGroupPrimitive.Root> & {
    full?: boolean;
    variant?: 'default' | 'outline';
}) {
    return (
        <ToggleGroupPrimitive.Root
            data-slot="toggle-group"
            data-variant={variant}
            data-full={full ? '' : undefined}
            className={cn(
                'border-input bg-background inline-flex h-8 items-center rounded-lg border p-0.5',
                full && 'flex w-full',
                className,
            )}
            {...props}
        >
            {children}
        </ToggleGroupPrimitive.Root>
    );
}

function ToggleGroupItem({ className, children, ...props }: React.ComponentProps<typeof ToggleGroupPrimitive.Item>) {
    return (
        <ToggleGroupPrimitive.Item
            data-slot="toggle-group-item"
            className={cn(
                'text-muted-foreground inline-flex h-[26px] min-w-0 shrink-0 items-center justify-center gap-1.5 rounded-md bg-transparent px-[11px] text-xs leading-4 font-medium whitespace-nowrap transition-[color,background-color,border-color]',
                'hover:text-foreground',
                'data-[state=on]:bg-acc-soft data-[state=on]:text-acc-text data-[state=on]:font-semibold',
                'disabled:pointer-events-none disabled:opacity-45',
                "[[data-full]_&]:flex-1 [[data-full]_&]:px-0 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-3.5",
                className,
            )}
            {...props}
        >
            {children}
        </ToggleGroupPrimitive.Item>
    );
}

export { ToggleGroup, ToggleGroupItem };

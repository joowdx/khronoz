import * as React from 'react';
import { cn } from '@/lib/utils';
import { CheckIcon } from 'lucide-react';
import { Checkbox as CheckboxPrimitive } from 'radix-ui';

/**
 * The drawn box stays 18px; the ::before pseudo-element grows the hit area to
 * 28px, which clears WCAG 2.2's 24px target minimum without changing the
 * drawing. Checked-but-disabled is its own state, not a dimmed one: a manage
 * right implies its view right, so that box reads grey with an ink check
 * (11.94:1 light, 9.35:1 dark) rather than at 45% opacity.
 */
function Checkbox({ className, ...props }: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
    return (
        <CheckboxPrimitive.Root
            data-slot="checkbox"
            className={cn(
                'peer border-input bg-background relative size-[18px] shrink-0 rounded-[5px] border transition-[color,background-color,border-color]',
                'before:absolute before:-inset-[5px] before:content-[""]',
                'hover:border-foreground',
                'data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground',
                'data-[state=indeterminate]:border-primary data-[state=indeterminate]:bg-primary data-[state=indeterminate]:text-primary-foreground',
                'aria-invalid:border-destructive aria-invalid:focus-visible:outline-destructive',
                'disabled:bg-rule disabled:border-edge-soft disabled:hover:border-edge-soft disabled:cursor-not-allowed',
                'disabled:data-[state=checked]:bg-edge-soft disabled:data-[state=checked]:border-input disabled:data-[state=checked]:text-foreground',
                // The same two states again for aria-disabled, which is what a
                // box that must stay focusable uses instead — the permission
                // matrix's implied views, where the lock beside the box is the
                // explanation and a control out of the tab order could not be
                // read (§5.21).
                'aria-disabled:bg-rule aria-disabled:border-edge-soft aria-disabled:hover:border-edge-soft aria-disabled:cursor-default',
                'aria-disabled:data-[state=checked]:bg-edge-soft aria-disabled:data-[state=checked]:border-input aria-disabled:data-[state=checked]:text-foreground',
                className,
            )}
            {...props}
        >
            <CheckboxPrimitive.Indicator
                data-slot="checkbox-indicator"
                className="grid place-content-center text-current"
            >
                <CheckIcon className="size-3" strokeWidth={3} />
            </CheckboxPrimitive.Indicator>
        </CheckboxPrimitive.Root>
    );
}

export { Checkbox };

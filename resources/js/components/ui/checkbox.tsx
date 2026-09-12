import * as React from 'react';
import { cn } from '@/lib/utils';
import { CheckIcon } from 'lucide-react';
import { Checkbox as CheckboxPrimitive } from 'radix-ui';

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

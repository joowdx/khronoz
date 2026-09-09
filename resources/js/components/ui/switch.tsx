import * as React from 'react';
import { cn } from '@/lib/utils';
import { Switch as SwitchPrimitive } from 'radix-ui';

/**
 * 34x20 track, 14px thumb. Like the checkbox, the drawn control keeps its
 * size and ::before grows the hit area past 24px. One geometry only — the
 * design has no small switch.
 */
function Switch({ className, ...props }: React.ComponentProps<typeof SwitchPrimitive.Root>) {
    return (
        <SwitchPrimitive.Root
            data-slot="switch"
            className={cn(
                'peer border-input bg-background relative inline-flex h-5 w-[34px] shrink-0 items-center rounded-full border transition-[color,background-color,border-color]',
                'before:absolute before:-inset-[5px] before:content-[""]',
                'data-[state=checked]:border-primary data-[state=checked]:bg-primary',
                'disabled:bg-rule disabled:border-edge-soft disabled:cursor-not-allowed',
                className,
            )}
            {...props}
        >
            <SwitchPrimitive.Thumb
                data-slot="switch-thumb"
                className="bg-input data-[state=checked]:bg-primary-foreground pointer-events-none block size-3.5 rounded-full transition-transform data-[state=checked]:translate-x-[15px] data-[state=unchecked]:translate-x-px"
            />
        </SwitchPrimitive.Root>
    );
}

export { Switch };

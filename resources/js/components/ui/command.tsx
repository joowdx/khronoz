'use client';

import * as React from 'react';
import { Command as CommandPrimitive } from 'cmdk';
import { cn } from '@/lib/utils';
import { SearchIcon } from 'lucide-react';

import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';

function Command({ className, ...props }: React.ComponentProps<typeof CommandPrimitive>) {
    return (
        <CommandPrimitive
            data-slot="command"
            className={cn(
                'bg-popover text-popover-foreground flex h-full w-full flex-col overflow-hidden rounded-[10px]',
                className,
            )}
            {...props}
        />
    );
}

function CommandDialog({
    title = 'Command Palette',
    description = 'Search for a command to run...',
    children,
    className,
    showCloseButton = true,
    ...props
}: React.ComponentProps<typeof Dialog> & {
    title?: string;
    description?: string;
    className?: string;
    showCloseButton?: boolean;
}) {
    return (
        <Dialog {...props}>
            <DialogHeader className="sr-only">
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
            </DialogHeader>
            <DialogContent className={cn('overflow-hidden p-0', className)} showCloseButton={showCloseButton}>
                <Command className="[&_[cmdk-group-heading]]:text-muted-foreground **:data-[slot=command-input-wrapper]:h-12 [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group]]:px-2 [&_[cmdk-group]:not([hidden])_~[cmdk-group]]:pt-0 [&_[cmdk-input-wrapper]_svg]:h-5 [&_[cmdk-input-wrapper]_svg]:w-5 [&_[cmdk-input]]:h-12 [&_[cmdk-item]]:px-2 [&_[cmdk-item]]:py-3 [&_[cmdk-item]_svg]:h-5 [&_[cmdk-item]_svg]:w-5">
                    {children}
                </Command>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The ring belongs to the row (icon, field, bottom rule), not the inner
 * `<input>` — the same reasoning `tag-input.tsx` already documents for the
 * same shape of control. cmdk's own input is `h-10` inside this `h-9`
 * wrapper and carries no border of its own (`command-input-wrapper` owns the
 * `border-b`), so the global bordered-input focus rule (app.css, which draws
 * its ring just inside the focused element's own edge) traced a box 4px
 * taller than the row and offset right by the icon plus its gap — the resting
 * appearance of every opened picker, since `cmdk` autofocuses this input on
 * open. `focus-within:` moves the same -1px inset ring onto the row that
 * actually reads as the control, and `outline-none` suppresses the input's
 * own.
 */
function CommandInput({ className, ...props }: React.ComponentProps<typeof CommandPrimitive.Input>) {
    return (
        <div
            data-slot="command-input-wrapper"
            className="focus-within:outline-ring flex h-9 items-center gap-2 border-b px-3 focus-within:outline-2 focus-within:-outline-offset-1"
        >
            <SearchIcon className="size-4 shrink-0 opacity-50" />
            <CommandPrimitive.Input
                data-slot="command-input"
                className={cn(
                    'placeholder:text-muted-foreground flex h-10 w-full rounded-lg bg-transparent py-3 text-sm outline-none disabled:cursor-not-allowed disabled:opacity-45',
                    className,
                )}
                {...props}
            />
        </div>
    );
}

function CommandList({ className, ...props }: React.ComponentProps<typeof CommandPrimitive.List>) {
    return (
        <CommandPrimitive.List
            data-slot="command-list"
            className={cn('max-h-[300px] scroll-py-1 overflow-x-hidden overflow-y-auto', className)}
            {...props}
        />
    );
}

function CommandEmpty({ ...props }: React.ComponentProps<typeof CommandPrimitive.Empty>) {
    return <CommandPrimitive.Empty data-slot="command-empty" className="py-6 text-center text-sm" {...props} />;
}

function CommandGroup({ className, ...props }: React.ComponentProps<typeof CommandPrimitive.Group>) {
    return (
        <CommandPrimitive.Group
            data-slot="command-group"
            className={cn(
                'text-foreground [&_[cmdk-group-heading]]:text-muted-foreground overflow-hidden p-1 [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium',
                className,
            )}
            {...props}
        />
    );
}

function CommandSeparator({ className, ...props }: React.ComponentProps<typeof CommandPrimitive.Separator>) {
    return (
        <CommandPrimitive.Separator
            data-slot="command-separator"
            className={cn('bg-border -mx-1 h-px', className)}
            {...props}
        />
    );
}

function CommandItem({ className, ...props }: React.ComponentProps<typeof CommandPrimitive.Item>) {
    return (
        <CommandPrimitive.Item
            data-slot="command-item"
            className={cn(
                "data-[selected=true]:bg-row-hover [&_svg:not([class*='text-'])]:text-muted-foreground relative flex h-8 cursor-default items-center gap-[9px] rounded-md px-[9px] text-[13px] leading-[18px] font-medium select-none data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-45 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
                className,
            )}
            {...props}
        />
    );
}

function CommandShortcut({ className, ...props }: React.ComponentProps<'span'>) {
    return (
        <span
            data-slot="command-shortcut"
            className={cn('text-muted-foreground ml-auto text-xs tracking-widest', className)}
            {...props}
        />
    );
}

export {
    Command,
    CommandDialog,
    CommandInput,
    CommandList,
    CommandEmpty,
    CommandGroup,
    CommandItem,
    CommandShortcut,
    CommandSeparator,
};

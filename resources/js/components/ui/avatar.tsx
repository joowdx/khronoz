import * as React from 'react';
import { cn } from '@/lib/utils';
import { Avatar as AvatarPrimitive } from 'radix-ui';

/** The eight chip-ramp slots, as fill + text pairs. Slot 0 is the neutral one. */
const tintClasses = [
    'bg-muted text-muted-foreground',
    'bg-c1-fill text-c1-text',
    'bg-c2-fill text-c2-text',
    'bg-c3-fill text-c3-text',
    'bg-c4-fill text-c4-text',
    'bg-c5-fill text-c5-text',
    'bg-c6-fill text-c6-text',
    'bg-c7-fill text-c7-text',
    'bg-c8-fill text-c8-text',
] as const;

export type AvatarTint = 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8;

/**
 * Pick a chip slot from a name, so the same person is the same colour on every
 * screen and across sessions without storing anything. Decorative only: the
 * name is always beside the avatar, so the colour carries no meaning of its
 * own.
 */
export function avatarTint(name: string): AvatarTint {
    let hash = 0;

    for (let i = 0; i < name.length; i += 1) {
        hash = (hash * 31 + name.charCodeAt(i)) % 100_000;
    }

    return ((hash % 8) + 1) as AvatarTint;
}

/** "Corazon Dimaano" -> "CD"; a single word gives its first two letters. */
export function initials(name: string): string {
    const words = name.trim().split(/\s+/).filter(Boolean);
    const first = words.at(0) ?? '';
    const last = words.at(-1) ?? '';

    if (first === '') {
        return '';
    }

    return (words.length === 1 ? first.slice(0, 2) : first.slice(0, 1) + last.slice(0, 1)).toUpperCase();
}

/**
 * The design draws three avatars — 32, 28 and 24 (§4.3) — each with its own
 * type size. `md` is the 28px one (`.avatar--28`), which is what a two-line
 * table row and the sidebar's user menu use; it is the commonest of the three.
 */
function Avatar({
    className,
    size = 'default',
    ...props
}: React.ComponentProps<typeof AvatarPrimitive.Root> & {
    size?: 'default' | 'md' | 'sm' | 'lg';
}) {
    return (
        <AvatarPrimitive.Root
            data-slot="avatar"
            data-size={size}
            className={cn(
                'group/avatar relative flex size-8 shrink-0 overflow-hidden rounded-full select-none data-[size=lg]:size-10 data-[size=md]:size-7 data-[size=sm]:size-6',
                className,
            )}
            {...props}
        />
    );
}

function AvatarImage({ className, ...props }: React.ComponentProps<typeof AvatarPrimitive.Image>) {
    return (
        <AvatarPrimitive.Image
            data-slot="avatar-image"
            className={cn('aspect-square size-full', className)}
            {...props}
        />
    );
}

function AvatarFallback({
    className,
    tint = 0,
    ...props
}: React.ComponentProps<typeof AvatarPrimitive.Fallback> & { tint?: AvatarTint }) {
    return (
        <AvatarPrimitive.Fallback
            data-slot="avatar-fallback"
            className={cn(
                'flex size-full items-center justify-center rounded-full text-xs leading-4 font-semibold tracking-[0.01em] group-data-[size=lg]/avatar:text-sm group-data-[size=md]/avatar:text-[11px] group-data-[size=md]/avatar:leading-[14px] group-data-[size=sm]/avatar:text-[10px] group-data-[size=sm]/avatar:leading-[13px]',
                tintClasses[tint],
                className,
            )}
            {...props}
        />
    );
}

function AvatarGroup({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="avatar-group"
            className={cn(
                'group/avatar-group *:data-[slot=avatar]:ring-background flex -space-x-2 *:data-[slot=avatar]:ring-2',
                className,
            )}
            {...props}
        />
    );
}

function AvatarGroupCount({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="avatar-group-count"
            className={cn(
                'bg-muted text-muted-foreground ring-background relative flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold ring-2 group-has-data-[size=lg]/avatar-group:size-10 group-has-data-[size=sm]/avatar-group:size-6',
                className,
            )}
            {...props}
        />
    );
}

export { Avatar, AvatarImage, AvatarFallback, AvatarGroup, AvatarGroupCount };

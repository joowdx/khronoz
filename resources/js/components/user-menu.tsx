import { Form, usePage } from '@inertiajs/react';
import { ChevronDown, LogOut, Sun } from 'lucide-react';
import { useId } from 'react';
import { destroy } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { Avatar, AvatarFallback, avatarTint, initials } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSegmented,
    DropdownMenuSegmentedItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance, SharedProps } from '@/types';

/**
 * The sidebar's footer: who you are, then what you can change about that.
 *
 * The trigger is a 40px row, not an icon — the owner asked for the name and
 * the address to be readable without opening anything (§5.3). Radix supplies
 * the menu's whole keyboard contract: arrow keys between items, Home/End,
 * typing to jump, Escape to close with focus returning to this button, and
 * `aria-haspopup="menu"` plus `aria-expanded` on the trigger (§5.14).
 *
 * The menu holds an identity block, the appearance switcher and Sign out.
 * There is no Account settings entry: the artboard has one, but no such page
 * exists in Milestone 1 and a menu item that goes nowhere is worse than an
 * absent one. It arrives with the agency-settings screens.
 */
const CHOICES: { value: Appearance; label: string }[] = [
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
    { value: 'system', label: 'System' },
];

export function UserMenu() {
    // `auth` is only shared once a user is signed in; the shell never renders
    // for a guest, but the prop is read defensively the way the rest of the
    // shell reads it.
    const { auth } = usePage<SharedProps>().props;
    const user = auth?.user;
    const { appearance, setAppearance } = useAppearance();
    const appearanceLabelId = useId();

    if (!user) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="hover:bg-side-hover aria-expanded:bg-side-hover flex h-10 w-full items-center gap-2.5 rounded-lg px-2 text-left transition-[color,background-color,border-color]"
                >
                    <Avatar size="md" aria-hidden>
                        <AvatarFallback tint={avatarTint(user.name)}>{initials(user.name)}</AvatarFallback>
                    </Avatar>
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-[13px] leading-[17px] font-medium">{user.name}</span>
                        <span className="text-muted-foreground block truncate text-xs leading-4">{user.email}</span>
                    </span>
                    <ChevronDown aria-hidden strokeWidth={1.5} className="text-muted-foreground size-4 shrink-0" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="start" sideOffset={6} className="w-[268px]">
                {/* §5.14's user menu opens with an identity block. It repeats
                    the trigger on purpose: the menu is also what the collapsed
                    rail's avatar-only trigger opens, and there the name and the
                    address appear nowhere else. */}
                <div className="border-border mb-1 flex items-center gap-2.5 border-b px-[9px] pt-2 pb-2.5">
                    <Avatar size="md" aria-hidden>
                        <AvatarFallback tint={avatarTint(user.name)}>{initials(user.name)}</AvatarFallback>
                    </Avatar>
                    <span className="min-w-0">
                        <span className="block truncate text-[13px] leading-[17px] font-semibold">{user.name}</span>
                        <span className="text-muted-foreground block truncate text-xs leading-4">{user.email}</span>
                    </span>
                </div>
                <div className="px-[9px] pt-1.5 pb-2">
                    <span
                        id={appearanceLabelId}
                        className="flex items-center gap-[9px] pb-[7px] text-[13px] leading-[18px] font-medium"
                    >
                        <Sun aria-hidden strokeWidth={1.5} className="text-muted-foreground size-4 shrink-0" />
                        Appearance
                    </span>
                    <DropdownMenuSegmented
                        value={appearance}
                        onValueChange={(value) => setAppearance(value as Appearance)}
                        aria-labelledby={appearanceLabelId}
                    >
                        {CHOICES.map((choice) => (
                            <DropdownMenuSegmentedItem key={choice.value} value={choice.value}>
                                {choice.label}
                            </DropdownMenuSegmentedItem>
                        ))}
                    </DropdownMenuSegmented>
                </div>
                <DropdownMenuSeparator />
                {/* Signing out is a POST, so it stays a real form submit rather
                    than a link. */}
                <Form {...destroy.form()}>
                    {({ processing }) => (
                        <DropdownMenuItem asChild disabled={processing}>
                            <button type="submit" className="w-full">
                                <LogOut aria-hidden strokeWidth={1.5} />
                                Sign out
                            </button>
                        </DropdownMenuItem>
                    )}
                </Form>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

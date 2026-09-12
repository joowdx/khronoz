import { Form, Link, usePage } from '@inertiajs/react';
import { ChevronDown, LogOut, Sun, Settings } from 'lucide-react';
import { edit as profile } from '@/routes/settings/profile';
import { useId } from 'react';
import { show as legalDocument } from '@/routes/legal';
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

const CHOICES: { value: Appearance; label: string }[] = [
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
    { value: 'system', label: 'System' },
];

export function UserMenu() {
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
                    className="hover:bg-side-hover aria-expanded:bg-side-hover flex h-10 w-full items-center gap-2.5 rounded-lg px-2 text-left transition-[color,background-color,border-color] group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0"
                >
                    <Avatar size="md" aria-hidden>
                        <AvatarFallback tint={avatarTint(user.name)}>{initials(user.name)}</AvatarFallback>
                    </Avatar>
                    <span className="min-w-0 flex-1 group-data-[collapsible=icon]:hidden">
                        <span className="block truncate text-[13px] leading-[17px] font-medium">{user.name}</span>
                        <span className="text-muted-foreground block truncate text-xs leading-4">{user.email}</span>
                    </span>
                    <ChevronDown
                        aria-hidden
                        strokeWidth={1.5}
                        className="text-muted-foreground size-4 shrink-0 group-data-[collapsible=icon]:hidden"
                    />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="start" sideOffset={6} className="w-[268px]">
                <div className="border-border mb-1 flex items-center gap-2.5 border-b px-[9px] pt-2 pb-2.5">
                    <Avatar size="md" aria-hidden>
                        <AvatarFallback tint={avatarTint(user.name)}>{initials(user.name)}</AvatarFallback>
                    </Avatar>
                    <span className="min-w-0">
                        <span className="block truncate text-[13px] leading-[17px] font-semibold">{user.name}</span>
                        <span className="text-muted-foreground block truncate text-xs leading-4">{user.email}</span>
                    </span>
                </div>
                <DropdownMenuItem asChild>
                    <Link href={profile()}>
                        <Settings aria-hidden strokeWidth={1.5} />
                        Account settings
                    </Link>
                </DropdownMenuItem>
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
                <DropdownMenuItem asChild>
                    <Link href={legalDocument({ document: 'privacy-policy' })}>Privacy Policy</Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href={legalDocument({ document: 'user-agreement' })}>User Agreement</Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
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

import { Form, Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { initials } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { enter, index, leave } from '@/routes/platform/agencies';
import type { Agency, SharedProps } from '@/types';

function tile(agency: Agency): string {
    return agency.code.length <= 4 ? agency.code.toUpperCase() : initials(agency.name);
}

function descriptor(agency: Agency): string {
    return agency.platform ? 'Platform' : agency.code;
}

function Identity({ agency }: { agency: Agency }) {
    return (
        <>
            <span
                aria-hidden
                className="bg-acc-soft text-acc-text flex size-8 shrink-0 items-center justify-center rounded-lg text-[11px] leading-[14px] font-semibold tracking-[0.01em]"
            >
                {tile(agency)}
            </span>
            <span className="min-w-0 flex-1 group-data-[collapsible=icon]:hidden">
                <span className="line-clamp-2 block text-sm leading-[18px] font-semibold">{agency.name}</span>
                <span className="text-muted-foreground block truncate text-xs leading-4">{descriptor(agency)}</span>
            </span>
        </>
    );
}

export function AgencySwitcher() {
    const { auth, agency, agencies } = usePage<SharedProps>().props;

    if (!agency) {
        return null;
    }

    if (!auth?.user?.platform) {
        return (
            <div className="px-2.5 pt-2.5 group-data-[collapsible=icon]:px-4">
                <div className="flex items-center gap-2.5 p-1.5 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0">
                    <Identity agency={agency} />
                </div>
            </div>
        );
    }

    return (
        <div className="px-2.5 pt-2.5 group-data-[collapsible=icon]:px-4">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        className="hover:bg-side-hover aria-expanded:bg-side-hover flex w-full items-center gap-2.5 rounded-lg p-1.5 text-left transition-[color,background-color,border-color] group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0"
                    >
                        <Identity agency={agency} />
                        <ChevronDown
                            aria-hidden
                            strokeWidth={1.5}
                            className="text-muted-foreground size-4 shrink-0 self-center group-data-[collapsible=icon]:hidden"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" side="bottom" className="w-(--radix-dropdown-menu-trigger-width)">
                    {!agency.platform && (
                        <>
                            <Form {...leave.form()}>
                                {({ processing }) => (
                                    <DropdownMenuItem asChild disabled={processing}>
                                        <button type="submit" className="w-full" disabled={processing}>
                                            Back to the platform
                                        </button>
                                    </DropdownMenuItem>
                                )}
                            </Form>
                            <DropdownMenuSeparator />
                        </>
                    )}
                    {(agencies ?? []).map((option) => (
                        <Form key={option.id} {...enter.form(option)}>
                            {({ processing }) => (
                                <DropdownMenuItem asChild disabled={processing || option.id === agency.id}>
                                    <button
                                        type="submit"
                                        className="w-full"
                                        disabled={processing || option.id === agency.id}
                                    >
                                        {option.name}
                                    </button>
                                </DropdownMenuItem>
                            )}
                        </Form>
                    ))}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem asChild>
                        <Link href={index()} className="w-full">
                            Manage agencies
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

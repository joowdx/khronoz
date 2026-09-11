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

/**
 * The block at the top of the sidebar: whose records you are looking at.
 *
 * For an agency user it is static — they have exactly one agency and there is
 * nothing to switch to, so it carries no affordance that suggests otherwise.
 * For a platform user it is a menu: enter an agency to adopt it as the tenant
 * for the rest of the session, or leave back to the platform itself (see
 * SetTenant).
 *
 * Geometry is §5.3's agency block: a 32px tile on `--acc-soft` with the code
 * in `--acc-text` at 11/14/600, the name at 14/18/600 over at most two lines,
 * and a second line beneath it. In the artboard that second line is the
 * headcount, "214 employees". There are no employees in Milestone 1 —
 * `Employee` arrives in Milestone 2 — so rather than invent a number it reads
 * the agency's own code, which is also the only place a screen reader hears
 * the code at all: the tile is decoration.
 */
function tile(agency: Agency): string {
    // An agency code is short and is the agency's identity: DOH, PHO, LGU-IL.
    // The platform row's code is the literal word "platform", which is not an
    // identity anyone reads, so that one falls back to its name's initials.
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
            {/*
              The tile survives into the rail and this does not: the tile is
              already the agency's code, so collapsing loses the full name and
              nothing else. It is `aria-hidden`, so the name here is the only
              one a screen reader hears — which is why the switch is `hidden`
              and not a render branch: the markup stays, the rail just does not
              draw it.
            */}
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

    // Both forms are the same box — 10 of outer padding and 6 of inner, so the
    // tile lands on the 16 §5.3 asks for and the day strip below starts at the
    // same offset whichever form is rendered. The inner padding exists so the
    // menu form's hover tint can bleed past the text.
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

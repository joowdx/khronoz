import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useId } from 'react';
import { useSidebar } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * One nav destination.
 *
 * `href` is a plain path — pass a Wayfinder call's `.url`, the same rule
 * `BreadcrumbItem.href` follows (.ai/rules/pages.md).
 */
export interface NavItem {
    title: string;
    href: string;
    icon: LucideIcon;
    /**
     * A count on a nav item means work is held up, not how many rows the page
     * has (docs/design/08-interface.md §6.2). `attention` swaps the neutral
     * pill for the amber one. Nothing in Milestone 1 holds work up, so no item
     * carries a count yet; Workdays and Ledgers will in Milestone 6.
     */
    count?: { value: number; attention?: boolean };
}

/** A labelled run of items, or an unlabelled one when `label` is omitted. */
export interface NavGroup {
    label?: string;
    items: NavItem[];
}

/**
 * The sidebar's navigation, rendered from data.
 *
 * A milestone adds a group to the array `app-sidebar.tsx` builds; it does not
 * add markup here. That is the whole point of the shape — the groups that
 * arrive later are already named in §6.2 and listed in app-sidebar.tsx.
 *
 * Structure is §5.3's accessibility contract: one `<nav aria-label="Main">`,
 * a `<ul>` per group, each labelled group's list pointed at its own heading by
 * `aria-labelledby`, and the current item marked with `aria-current="page"` —
 * that attribute, not the accent tint, is what a screen reader announces.
 *
 * **In §4.3's 64px rail** every item is its icon alone, named by a tooltip.
 * Nothing is removed from the accessibility tree by that: the link's own text
 * is what the tooltip repeats, and it is hidden visually rather than dropped,
 * so the reading is identical at either width. The counts survive as a dot in
 * the icon's corner — the number does not fit, but the fact that something is
 * held up is exactly what the rail must not swallow.
 */
export function NavMain({ groups }: { groups: NavGroup[] }) {
    const { url } = usePage();
    const path = url.split('?')[0] ?? url;
    const prefix = useId();
    const { state } = useSidebar();
    const rail = state === 'collapsed';

    return (
        <nav
            aria-label="Main"
            className="min-h-0 flex-1 overflow-auto px-3 pt-3 pb-4 group-data-[collapsible=icon]:overflow-x-hidden group-data-[collapsible=icon]:px-4"
        >
            {groups.map((group, index) => {
                const labelId = `${prefix}-nav-${index}`;

                return (
                    <Fragment key={group.label ?? index}>
                        {/*
                          A div, not a heading. §5.3 allows either a heading or
                          an `aria-labelledby` on the nested list, and the
                          sidebar precedes the page in the DOM: as a heading
                          this would land above the page's own <h1> in the
                          outline, so a screen-reader user browsing by heading
                          would meet "Platform" before "Dashboard".
                        */}
                        {group.label && (
                            /*
                              In the rail the words do not fit, but the break
                              they mark still has to be visible or five groups
                              run together as one column of icons — so the
                              label clips itself to a 1px rule. It keeps its
                              id: the list below is still pointed at it by
                              `aria-labelledby`, and clipped text is read.
                            */
                            <div
                                id={labelId}
                                className="text-muted-foreground group-data-[collapsible=icon]:bg-border px-2 pt-3.5 pb-1.5 text-xs leading-4 font-medium group-data-[collapsible=icon]:my-2.5 group-data-[collapsible=icon]:h-px group-data-[collapsible=icon]:overflow-hidden group-data-[collapsible=icon]:p-0"
                            >
                                {group.label}
                            </div>
                        )}
                        <ul aria-labelledby={group.label ? labelId : undefined}>
                            {group.items.map((item) => {
                                const Icon = item.icon;
                                // `/users` is also current while editing one of
                                // them at `/users/{id}/edit`.
                                const current = path === item.href || path.startsWith(`${item.href}/`);

                                return (
                                    <li key={item.href}>
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Link
                                                    href={item.href}
                                                    aria-current={current ? 'page' : undefined}
                                                    className={cn(
                                                        'group/nav flex h-8 items-center gap-2.5 rounded-lg px-2.5 text-[13px] leading-[18px] font-medium transition-[color,background-color,border-color]',
                                                        'hover:bg-side-hover',
                                                        'aria-[current=page]:bg-acc-soft aria-[current=page]:text-acc-text aria-[current=page]:font-semibold',
                                                        // The rail: a 32 square centred in 64,
                                                        // and `relative` so the count has a corner
                                                        // to sit in.
                                                        'group-data-[collapsible=icon]:relative group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0',
                                                    )}
                                                >
                                                    <Icon
                                                        aria-hidden
                                                        strokeWidth={1.5}
                                                        className="text-muted-foreground group-aria-[current=page]/nav:text-acc-text size-4 shrink-0"
                                                    />
                                                    <span className="min-w-0 flex-1 truncate group-data-[collapsible=icon]:hidden">
                                                        {item.title}
                                                    </span>
                                                    {item.count !== undefined && (
                                                        <>
                                                            {/*
                                                              The same element either way: a pill
                                                              with the number, and in the rail the
                                                              same pill shrunk to a 6px dot in the
                                                              icon's corner. It keeps its colour,
                                                              so an amber dot still means amber.
                                                            */}
                                                            <span
                                                                aria-hidden
                                                                className={cn(
                                                                    'inline-flex h-[18px] min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-[11px] leading-[14px] font-semibold tabular-nums',
                                                                    item.count.attention
                                                                        ? 'bg-attention-soft text-attention'
                                                                        : 'bg-rule text-muted-foreground',
                                                                    'group-data-[collapsible=icon]:absolute group-data-[collapsible=icon]:top-0.5 group-data-[collapsible=icon]:right-0.5 group-data-[collapsible=icon]:size-1.5 group-data-[collapsible=icon]:min-w-0 group-data-[collapsible=icon]:overflow-hidden group-data-[collapsible=icon]:bg-current group-data-[collapsible=icon]:p-0 group-data-[collapsible=icon]:text-transparent',
                                                                )}
                                                            >
                                                                {item.count.value}
                                                            </span>
                                                            {/*
                                                              "24" on its own means nothing in the
                                                              accessibility tree, so the link's name
                                                              spells the count out: §5.3.
                                                            */}
                                                            <span className="sr-only">
                                                                , {item.count.value} need attention
                                                            </span>
                                                        </>
                                                    )}
                                                </Link>
                                            </TooltipTrigger>
                                            {/*
                                              Only in the rail. Expanded, the label is already
                                              beside the icon and a tooltip repeating it is
                                              noise that also covers the item below.
                                            */}
                                            <TooltipContent side="right" hidden={!rail}>
                                                {item.title}
                                            </TooltipContent>
                                        </Tooltip>
                                    </li>
                                );
                            })}
                        </ul>
                    </Fragment>
                );
            })}
        </nav>
    );
}

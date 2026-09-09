import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useId } from 'react';
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
 */
export function NavMain({ groups }: { groups: NavGroup[] }) {
    const { url } = usePage();
    const path = url.split('?')[0] ?? url;
    const prefix = useId();

    return (
        <nav aria-label="Main" className="min-h-0 flex-1 overflow-auto px-3 pt-3 pb-4">
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
                            <div
                                id={labelId}
                                className="text-muted-foreground px-2 pt-3.5 pb-1.5 text-xs leading-4 font-medium"
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
                                        <Link
                                            href={item.href}
                                            aria-current={current ? 'page' : undefined}
                                            className={cn(
                                                'group/nav flex h-8 items-center gap-2.5 rounded-lg px-2.5 text-[13px] leading-[18px] font-medium transition-[color,background-color,border-color]',
                                                'hover:bg-side-hover',
                                                'aria-[current=page]:bg-acc-soft aria-[current=page]:text-acc-text aria-[current=page]:font-semibold',
                                            )}
                                        >
                                            <Icon
                                                aria-hidden
                                                strokeWidth={1.5}
                                                className="text-muted-foreground group-aria-[current=page]/nav:text-acc-text size-4 shrink-0"
                                            />
                                            <span className="min-w-0 flex-1 truncate">{item.title}</span>
                                            {item.count !== undefined && (
                                                <>
                                                    <span
                                                        aria-hidden
                                                        className={cn(
                                                            'inline-flex h-[18px] min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-[11px] leading-[14px] font-semibold tabular-nums',
                                                            item.count.attention
                                                                ? 'bg-attention-soft text-attention'
                                                                : 'bg-rule text-muted-foreground',
                                                        )}
                                                    >
                                                        {item.count.value}
                                                    </span>
                                                    {/*
                                                      "24" on its own means nothing in the
                                                      accessibility tree, so the link's name
                                                      spells the count out: §5.3.
                                                    */}
                                                    <span className="sr-only">, {item.count.value} need attention</span>
                                                </>
                                            )}
                                        </Link>
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

import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useId } from 'react';
import { useSidebar } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export interface NavItem {
    title: string;
    href: string;
    icon: LucideIcon;
    count?: { value: number; attention?: boolean };
}

export interface NavGroup {
    label?: string;
    items: NavItem[];
}

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
                        {group.label && (
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
                                                            <span className="sr-only">
                                                                , {item.count.value} need attention
                                                            </span>
                                                        </>
                                                    )}
                                                </Link>
                                            </TooltipTrigger>
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

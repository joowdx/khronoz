import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { SidebarTrigger } from '@/components/ui/sidebar';

/**
 * The sticky title bar. Every page in the shell uses this one component.
 *
 * 56 high, on the canvas, `position: sticky; top: 0` at z-index 30, with 32 of
 * padding either side. It takes its 1px bottom rule only once the scroller has
 * moved: `app-layout.tsx` stamps `data-stuck="1"` on the scroll container and
 * that is what turns the transparent border into `--line` (§5.2, §6.2). The
 * negative horizontal margin cancels the content column's own 32 so the bar
 * and its rule reach both edges, then re-applies the same 32 to its contents.
 *
 * Because the bar lives inside the scroll container, a table head below it can
 * stick at `top: var(--bar-h)` and land exactly under it (§5.13).
 *
 * §5.2 allows three forms and no fourth:
 *
 * | Form                  | How                            | Used on               |
 * | --------------------- | ------------------------------ | --------------------- |
 * | Plain title           | `title`                        | Users, Agencies       |
 * | Title with breadcrumb | `title` + `breadcrumb`         | Invite user, any page one level down |
 * | Month stepper         | not built — see below          | month-scoped pages    |
 *
 * The month stepper is the title on month-scoped pages: two 32px icon buttons
 * either side of the month at 32/38/700. It is not built here, and not because
 * it is hard — it needs a month to step through, which means a page whose data
 * is month-scoped. The first of those is Workdays, in Milestone 6; the roster
 * and the ledgers follow. Building it now would mean inventing the query
 * string, the focus move onto the new `<h1>` and the polite announcement §5.2
 * requires, against no page that can use them.
 *
 * The subtitle renders immediately below the bar rather than inside it: 56px
 * cannot hold a 24/30 title and a second line, and the artboards put `.sub` at
 * the top of the page column. It is the page's one-line status, and `live`
 * gives it the small positive dot a live value carries (§6.3).
 */
export function PageHeader({
    title,
    breadcrumb,
    description,
    live = false,
    actions,
}: {
    title: string;
    /** The parent page. One level only, and always a real link (§5.2). */
    breadcrumb?: { title: string; href: string };
    /** The page's one-line status, under the bar. */
    description?: ReactNode;
    /** Marks `description` as a live value: a 6px positive dot, never animated. */
    live?: boolean;
    /** The page's one primary action, at the right end. */
    actions?: ReactNode;
}) {
    return (
        <>
            <header className="bg-background group-data-[stuck=1]/scroll:border-b-border sticky top-0 z-30 -mx-8 flex h-14 items-center gap-3 border-b border-transparent px-8">
                {/* The sidebar is a sheet below md, so its trigger belongs to the
                    bar there and nowhere else. */}
                <SidebarTrigger className="-ml-1 size-8 md:hidden" />
                {breadcrumb && (
                    <span className="text-muted-foreground flex shrink-0 items-baseline gap-2 text-[13px] leading-[18px] font-medium">
                        <Link href={breadcrumb.href} className="hover:text-acc-text">
                            {breadcrumb.title}
                        </Link>
                        <ChevronRight aria-hidden strokeWidth={1.5} className="text-input size-3.5 self-center" />
                    </span>
                )}
                <h1 className="truncate text-2xl leading-[30px] font-bold tracking-[-0.011em]">{title}</h1>
                <span className="flex-1" />
                {actions}
            </header>
            {description && (
                <p className="text-muted-foreground flex items-center gap-[7px] pt-1.5 pb-6 text-[13px] leading-[18px]">
                    {live && <span aria-hidden className="bg-positive size-1.5 shrink-0 rounded-full" />}
                    {description}
                </p>
            )}
        </>
    );
}

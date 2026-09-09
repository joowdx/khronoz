import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { SidebarTrigger } from '@/components/ui/sidebar';

/**
 * Every page in the shell renders this first. It draws two things:
 *
 * 1. The **sticky bar**: 56 high, on the canvas, `position: sticky; top: 0;
 *    left: 0` at z-index 30, always carrying its 1px bottom rule. It holds
 *    the sidebar trigger and the breadcrumb trail, and nothing else. The
 *    negative horizontal margin cancels the content column's own 32 so the
 *    bar and its rule reach both edges, then re-applies the same 32 to its
 *    contents.
 * 2. The **heading row** in the content: the page title with its description
 *    on the left, the page's one primary action on the right.
 *
 * **`left-0` is load-bearing, not decorative.** `position: sticky` only pins
 * the axes it is given an offset for — an index panel wide enough to scroll
 * `#main-content` sideways (pages/employees/index.tsx, pages/units/index.tsx)
 * moves this element's static-position box left along with everything else,
 * and `top: 0` alone re-pins it only vertically. MEASURED before `left-0`
 * existed, scrolled fully right: 1440/1366 no-op (nothing scrolls there),
 * 1280 left a 42px strip of the bar uncovered, 800 left half the bar gone
 * (rect left −274 / right 278), and 500 put the bar entirely off-screen
 * (rect left −574 / right −74) with a table row painting through where it
 * used to be. `left: 0` re-pins the other axis, and the bar now covers the
 * full viewport width at every size, scrolled or not.
 *
 * The title and the action are deliberately *not* in the bar (owner,
 * 2026-09-09, following the sibling project `paayo`, whose bar carries the
 * trigger and the trail while `Heading` and the primary action sit in a
 * `justify-between` row at the top of the page). Two reasons it is better:
 * a 56px bar cannot hold a 24/30 title, its description and a 36px button
 * without crushing all three, and a title that scrolls away with its own
 * content reads as belonging to the page rather than to the chrome. What
 * stays pinned is what stays useful when scrolled: where you are, and the way
 * back out.
 *
 * The rule and the trigger are unconditional. The artboards showed the rule
 * appearing on scroll and `app-layout.tsx` still stamps `data-stuck` for
 * anything that wants it, but every Milestone 1 page is shorter than the
 * viewport, so the scroller never moved and the bar read as no bar at all.
 *
 * Because the bar lives inside the scroll container, a table head below it can
 * stick at `top: var(--bar-h)` and land exactly under it (§5.13).
 *
 * The month stepper form (§5.2) is not built: it needs a page whose data is
 * month-scoped, and the first of those is Workdays in Milestone 6. It will
 * replace the title in the heading row, not in the bar.
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
    /** The page's one-line status, under the title. */
    description?: ReactNode;
    /** Marks `description` as a live value: a 6px positive dot, never animated. */
    live?: boolean;
    /** The page's one primary action, at the right of the heading row. */
    actions?: ReactNode;
}) {
    return (
        <>
            <header className="bg-background border-b-border sticky top-0 left-0 z-30 -mx-8 flex h-14 items-center gap-2 border-b px-8">
                <SidebarTrigger className="-ml-1 size-8" />
                {/* The trail is the bar's whole job: it says where you are and
                    gives the way back. It never renders empty, because the
                    current page is always its last item. */}
                <nav aria-label="Breadcrumb" className="min-w-0">
                    <ol className="text-muted-foreground flex items-center gap-2 text-[13px] leading-[18px] font-medium">
                        {breadcrumb && (
                            <li className="flex shrink-0 items-center gap-2">
                                <Link href={breadcrumb.href} className="hover:text-acc-text">
                                    {breadcrumb.title}
                                </Link>
                                <ChevronRight aria-hidden strokeWidth={1.5} className="text-input size-3.5" />
                            </li>
                        )}
                        <li className="text-foreground min-w-0 truncate" aria-current="page">
                            {title}
                        </li>
                    </ol>
                </nav>
            </header>
            <div className="flex flex-wrap items-start justify-between gap-4 pt-6 pb-6">
                <div className="min-w-0">
                    <h1 className="truncate text-2xl leading-[30px] font-bold tracking-[-0.011em]">{title}</h1>
                    {description && (
                        <p className="text-muted-foreground flex items-center gap-[7px] pt-1.5 text-[13px] leading-[18px]">
                            {live && <span aria-hidden className="bg-positive size-1.5 shrink-0 rounded-full" />}
                            {description}
                        </p>
                    )}
                </div>
                {actions}
            </div>
        </>
    );
}

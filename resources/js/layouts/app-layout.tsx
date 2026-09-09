import { usePage } from '@inertiajs/react';
import { type CSSProperties, type ReactNode, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { AppSidebar } from '@/components/app-sidebar';
// Toaster comes from the shadcn wrapper so its custom properties (--popover, --border, --radius) are set to khronoz tokens.
import { Toaster } from '@/components/ui/sonner';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import type { BreadcrumbItem as Crumb, SharedProps } from '@/types';

/**
 * The application shell: a 248px sidebar, then one scrolling column.
 *
 * The scroll container is the column itself, not the window, because that is
 * what makes the design's two sticky layers possible: the title bar sticks at
 * `top: 0` and a table head at `top: var(--bar-h)` lands under it (§6.2). It
 * also owns `data-stuck`, which is how the bar knows to take its 1px rule —
 * the bar cannot see its own scroll offset, and the rule appearing on scroll
 * is what tells the reader the bar is floating over content.
 *
 * **The page's horizontal padding (`px-8 pb-12`) lives on `#main-content`
 * itself, not on a wrapper `<div>` around `{children}`.** It used to be the
 * wrapper's (fix round 2: this is what made `PageHeader`'s `sticky left`
 * a no-op). The reason is narrower than fix round 2 first wrote it, and the
 * first version was wrong: a sticky offset is clamped to the element's own
 * *containing block*, not to the scroll container as such, and a block-level
 * `width: auto` box exactly fills its containing block — zero slack, nothing
 * for `left` to shift within — so when that containing block is itself an
 * ordinary wrapper `<div>` that just rides along with the scroll, the sticky
 * element rides along with it too, indistinguishable from `position: static`.
 * DISPROVED the "any intervening div, any width, disqualifies" version of
 * this rule by probe: a header nested one wrapper deep inside a 1600px-wide
 * wrapper, given an explicit `width: 500px`, pinned perfectly — nesting alone
 * is not disqualifying. And a 200px header inside a 500px auto-width wrapper
 * shifted exactly 300px, its slack, before it started travelling with the
 * scroll — partial stickiness, not the all-or-nothing the old text claimed.
 * A **direct child of `#main-content` works because `#main-content` *is* the
 * scroll container**: its own content box is what the sticky calculation
 * measures against, so the child has the full `scrollWidth − clientWidth` of
 * slack rather than whatever a wrapper's own sizing happens to leave it.
 * Staying a direct child is still the rule — it is the one arrangement
 * guaranteed to carry all the slack the scroll ever needs, without having to
 * reason about each wrapper's own width — but the reason is slack, not
 * depth. `<header>` (`page-header.tsx`) must stay a direct child of this div,
 * and this div is therefore what carries the padding a wrapper used to.
 *
 * One more failure mode this shape does not save you from, which fix round 3
 * had to add a second offset for: a direct child's sticky `left` resolves
 * against the scroll container's *padding* box, so once the padding above
 * moved onto `#main-content` itself, `left: 0` started pinning 32px inside
 * `#main-content`'s own edge rather than flush with it — correct only at the
 * one scroll position where the far-edge clamp happens to land there anyway.
 * `page-header.tsx` now uses `-left-8` to cancel that padding; see its
 * docblock for the measurements. Trailing padding (`pb-12`, and `px-8` on the
 * far side of a horizontal scroll) is still honoured correctly by the browser
 * on the scrolling element itself — verified in the same isolated page — so
 * nothing here trades one defect for another.
 *
 * Overlays are not parented here: Radix portals every menu, sheet and dialog
 * out of the scroller, which is the only reason they do not scroll away from
 * their triggers (§9.4 trap 5). Do not disable that.
 */
export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    /**
     * Accepted for the pages that still pass it. Depth now belongs to the
     * sticky title bar, which takes a single parent through
     * `PageHeader`'s `breadcrumb` prop — §5.2 allows one level and no
     * trailing chevron for the current page, so a breadcrumb *list* has
     * nothing to render. Nothing here reads it.
     */
    breadcrumbs?: Crumb[];
    children: ReactNode;
}) {
    // `flash` is only shared from Task 6 on; tolerate it being absent at
    // runtime even though SharedProps declares it as required.
    const { flash } = usePage<SharedProps>().props;
    const [stuck, setStuck] = useState(false);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    return (
        <SidebarProvider className="h-svh" style={{ '--sidebar-width': 'var(--side-w)' } as CSSProperties}>
            {/*
              The nav repeats on every page, so there is a way past it
              (WCAG 2.4.1). Invisible until focused, which is why it does not
              appear in the artboards; §5.18's toast note already assumes the
              product has one.
            */}
            <a
                href="#main-content"
                className="focus:bg-primary focus:text-primary-foreground sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[60] focus:rounded-lg focus:px-3.5 focus:py-2 focus:text-[13px] focus:leading-[18px] focus:font-semibold"
            >
                Skip to content
            </a>
            <AppSidebar />
            <SidebarInset className="min-w-0 overflow-hidden">
                <div
                    id="main-content"
                    tabIndex={-1}
                    data-stuck={stuck ? '1' : '0'}
                    onScroll={(event) => {
                        const next = event.currentTarget.scrollTop > 0;

                        setStuck((previous) => (previous === next ? previous : next));
                    }}
                    // The padding belongs here, not on a wrapper around
                    // {children} — see the docblock above: a sticky-left
                    // PageHeader nested one div deeper never re-pins at all.
                    className="group/scroll relative min-h-0 flex-1 overflow-auto px-8 pb-12"
                >
                    {children}
                </div>
                <Toaster position="bottom-right" />
            </SidebarInset>
        </SidebarProvider>
    );
}

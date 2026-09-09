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
 * wrapper's (fix round 2: this is what made `PageHeader`'s `sticky left-0`
 * a no-op). A `position: sticky; left: 0` element only re-pins against its
 * nearest ancestor that IS the scroll container — nest it inside even one
 * plain `<div>` between it and `#main-content`, and the browser never
 * applies the horizontal correction at all, no matter the div's own width:
 * MEASURED (reproduced in an isolated static page with no app CSS at all)
 * that a sticky-left element inside such a wrapper travels the *entire*
 * scroll distance, as if `left` were never set, while the identical element
 * one level up — a direct child of the scrolling element — pins correctly.
 * Vertical (`top`) stickiness does not have this problem, which is why it
 * went unnoticed until the shell started scrolling sideways (I7, fix round
 * 1). `<header>` (`page-header.tsx`) must stay a direct child of this div,
 * and this div is therefore what carries the padding a wrapper used to.
 * Trailing padding (`pb-12`, and `px-8` on the far side of a horizontal
 * scroll) is still honoured correctly by the browser on the scrolling
 * element itself — verified in the same isolated page — so nothing here
 * trades one defect for another.
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

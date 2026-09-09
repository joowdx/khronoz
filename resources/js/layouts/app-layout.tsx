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
                    className="group/scroll relative min-h-0 flex-1 overflow-auto"
                >
                    <div className="px-8 pb-12">{children}</div>
                </div>
                <Toaster position="bottom-right" />
            </SidebarInset>
        </SidebarProvider>
    );
}

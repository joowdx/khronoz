import { type CSSProperties, type ReactNode, useState } from 'react';
import { AppSidebar } from '@/components/app-sidebar';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import type { BreadcrumbItem as Crumb } from '@/types';

export default function AppLayout({ breadcrumbs = [], children }: { breadcrumbs?: Crumb[]; children: ReactNode }) {
    const [stuck, setStuck] = useState(false);

    return (
        <SidebarProvider className="h-svh" style={{ '--sidebar-width': 'var(--side-w)' } as CSSProperties}>
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
                    className="group/scroll relative min-h-0 flex-1 overflow-auto px-8 pb-12"
                >
                    {children}
                </div>
            </SidebarInset>
        </SidebarProvider>
    );
}

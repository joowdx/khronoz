import { Link, usePage } from '@inertiajs/react';
import { Fragment, useEffect, type ReactNode } from 'react';
import { Toaster, toast } from 'sonner';
import { AppSidebar } from '@/components/app-sidebar';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as Crumb, SharedProps } from '@/types';

export default function AppLayout({ breadcrumbs = [], children }: { breadcrumbs?: Crumb[]; children: ReactNode }) {
    // `flash` is only shared from Task 6 on; tolerate it being absent at
    // runtime even though SharedProps declares it as required.
    const { flash } = usePage<SharedProps>().props;
    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    return (
        <SidebarProvider>
            <AppSidebar />
            <SidebarInset>
                <header className="flex h-12 items-center gap-2 border-b px-4">
                    <SidebarTrigger className="-ml-1" />
                    <Separator orientation="vertical" className="mr-2 h-4" />
                    <Breadcrumb>
                        <BreadcrumbList>
                            {breadcrumbs.map((crumb, index) => (
                                <Fragment key={crumb.title}>
                                    {index > 0 && <BreadcrumbSeparator />}
                                    <BreadcrumbItem>
                                        {crumb.href ? (
                                            <BreadcrumbLink asChild>
                                                <Link href={crumb.href}>{crumb.title}</Link>
                                            </BreadcrumbLink>
                                        ) : (
                                            <BreadcrumbPage>{crumb.title}</BreadcrumbPage>
                                        )}
                                    </BreadcrumbItem>
                                </Fragment>
                            ))}
                        </BreadcrumbList>
                    </Breadcrumb>
                </header>
                <main className="flex flex-1 flex-col gap-6 p-6">{children}</main>
                <Toaster position="bottom-right" />
            </SidebarInset>
        </SidebarProvider>
    );
}

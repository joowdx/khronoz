import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { MonthStepper } from '@/components/month-stepper';
import { SidebarTrigger } from '@/components/ui/sidebar';

export function PageHeader({
    title,
    breadcrumb,
    description,
    live = false,
    actions,
    month,
}: {
    title: string;
    breadcrumb?: { title: string; href: string };
    description?: ReactNode;
    live?: boolean;
    actions?: ReactNode;
    month?: { value: string; onChange: (month: string) => void };
}) {
    return (
        <>
            <header className="bg-background border-b-border sticky top-0 -left-8 z-30 -mx-8 flex h-14 items-center gap-2 border-b px-8">
                <SidebarTrigger className="-ml-1 size-8" />
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
                    {month ? (
                        <MonthStepper value={month.value} onChange={month.onChange} />
                    ) : (
                        <h1 className="truncate text-2xl leading-[30px] font-bold tracking-[-0.011em]">{title}</h1>
                    )}
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

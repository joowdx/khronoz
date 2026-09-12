import { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';
import { dashboard, login } from '@/routes';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { BrandLink } from '@/components/marketing/brand';

const SECTIONS = [
    { href: '#product', label: 'Product' },
    { href: '#how', label: 'How it works' },
    { href: '#agencies', label: 'For agencies' },
];

export function SiteNav() {
    const { auth } = usePage<SharedProps>().props;
    const sentinel = useRef<HTMLDivElement>(null);
    const [stuck, setStuck] = useState(false);

    useEffect(() => {
        const target = sentinel.current;

        if (!target || typeof IntersectionObserver === 'undefined') {
            return;
        }

        const observer = new IntersectionObserver(([entry]) => {
            if (entry) {
                setStuck(!entry.isIntersecting);
            }
        });

        observer.observe(target);

        return () => observer.disconnect();
    }, []);

    return (
        <>
            <div ref={sentinel} aria-hidden="true" className="absolute top-0 size-px" />
            <header
                className={cn(
                    'bg-background sticky top-0 z-40 border-b motion-safe:transition-[border-color]',
                    stuck ? 'border-border' : 'border-transparent',
                )}
            >
                <div className="mx-auto flex h-16 max-w-[1200px] items-center gap-3 px-5 md:gap-[26px] md:px-10">
                    <BrandLink />
                    <nav aria-label="Sections" className="ml-2.5 hidden items-center gap-[26px] md:flex">
                        {SECTIONS.map((section) => (
                            <a
                                key={section.href}
                                href={section.href}
                                className="text-muted-foreground hover:text-foreground rounded-lg py-0.5 text-sm leading-5 font-medium"
                            >
                                {section.label}
                            </a>
                        ))}
                    </nav>
                    <span className="flex-1" />
                    <div className="flex items-center gap-[18px]">
                        <Link
                            href={auth.user ? dashboard() : login()}
                            className="text-acc-text rounded-lg py-0.5 text-sm leading-5 font-medium underline-offset-2 hover:underline"
                        >
                            {auth.user ? 'Dashboard' : 'Sign in'}
                        </Link>
                        <Button
                            asChild
                            className="h-11 px-4 text-sm leading-5 md:h-9 md:px-3.5 md:text-[13px] md:leading-[18px]"
                        >
                            <a href="#demo">Request a demo</a>
                        </Button>
                    </div>
                </div>
            </header>
        </>
    );
}

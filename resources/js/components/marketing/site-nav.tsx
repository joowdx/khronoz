import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { login } from '@/routes';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { BrandLink } from '@/components/marketing/brand';

const SECTIONS = [
    { href: '#product', label: 'Product' },
    { href: '#how', label: 'How it works' },
    { href: '#agencies', label: 'For agencies' },
];

/**
 * The 64px sticky nav. It takes its 1px rule only once the page has scrolled,
 * which a 1px sentinel above it reports: an IntersectionObserver costs nothing
 * per frame, where a scroll listener runs on every one. The colour change is
 * held back from anyone who asked for less motion.
 */
export function SiteNav() {
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
            {/* Out of flow, so watching the scroll costs the page no pixel. */}
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
                            href={login()}
                            className="text-acc-text rounded-lg py-0.5 text-sm leading-5 font-medium underline-offset-2 hover:underline"
                        >
                            Sign in
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

import { Link, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';
import { dashboard, login } from '@/routes';
import { LegalLinks } from '@/components/legal-links';
import { BrandLink } from '@/components/marketing/brand';

const LINK = 'text-muted-foreground hover:text-foreground rounded-lg py-0.5 text-[13px] leading-5 font-medium';

export function SiteFooter({ demo }: { demo: string }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <footer>
            <div className="mx-auto max-w-[1200px] border-t px-5 pt-[34px] pb-11 md:px-10">
                <div className="flex flex-col items-start gap-5 md:flex-row md:gap-10">
                    <div>
                        <BrandLink />
                        <p className="text-muted-foreground max-w-[380px] pt-2.5 text-[13px] leading-5">
                            Scheduling and daily time records for Philippine government agencies and private offices,
                            under Civil Service Commission rules.
                        </p>
                    </div>
                    <nav
                        aria-label="Footer"
                        className="flex flex-wrap items-center gap-[18px] pt-1 md:ml-auto md:gap-6"
                    >
                        <a href="#product" className={LINK}>
                            Product
                        </a>
                        <a href="#how" className={LINK}>
                            How it works
                        </a>
                        <Link href={auth.user ? dashboard() : login()} className={LINK}>
                            {auth.user ? 'Dashboard' : 'Sign in'}
                        </Link>
                        <a href={demo} className={LINK} aria-label="Contact us by email">
                            Contact
                        </a>
                    </nav>
                </div>
                <p className="border-rule text-muted-foreground mt-[26px] border-t pt-4 text-xs leading-[17px] tabular-nums">
                    &copy; 2026 khronoz
                </p>
                <div className="mt-4">
                    <LegalLinks />
                </div>
            </div>
        </footer>
    );
}

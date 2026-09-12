import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { edit as profile } from '@/routes/settings/profile';
import { edit as security } from '@/routes/settings/security';

export default function AccountLayout({ children, title }: { children: ReactNode; title: string }) {
    const { url } = usePage();
    const links = [
        { label: 'Profile', href: profile().url },
        { label: 'Security', href: security().url },
    ];
    return (
        <AppLayout>
            <PageHeader title={title} description="Manage your personal account and sign-in methods." />
            <nav aria-label="Account settings" className="mb-8 flex flex-wrap gap-2 border-b pb-4">
                {links.map((link) => (
                    <Link
                        key={link.href}
                        href={link.href}
                        aria-current={url.startsWith(link.href) ? 'page' : undefined}
                        className="text-muted-foreground hover:text-foreground aria-[current=page]:bg-muted aria-[current=page]:text-foreground rounded-lg px-3 py-2 text-sm font-medium"
                    >
                        {link.label}
                    </Link>
                ))}
            </nav>
            <div className="grid max-w-[640px] gap-8">{children}</div>
        </AppLayout>
    );
}

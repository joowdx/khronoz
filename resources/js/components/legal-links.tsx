import { Link } from '@inertiajs/react';
import { show } from '@/routes/legal';

export function LegalLinks() {
    return (
        <nav aria-label="Legal" className="text-muted-foreground flex flex-wrap gap-x-5 gap-y-2 text-xs leading-5">
            <Link href={show({ document: 'privacy-policy' })} className="rounded underline-offset-4 hover:underline">
                Privacy Policy
            </Link>
            <Link href={show({ document: 'user-agreement' })} className="rounded underline-offset-4 hover:underline">
                User Agreement
            </Link>
        </nav>
    );
}

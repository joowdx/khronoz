import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

export default function Welcome() {
    return (
        <main className="mx-auto flex min-h-screen max-w-2xl flex-col justify-center px-6 py-16">
            <h1 className="text-2xl font-semibold tracking-tight">khronoz</h1>
            <p className="text-muted-foreground mt-2 text-sm">
                Scheduling and Daily Time Record for Philippine government HR offices.
            </p>
            <Button asChild className="mt-8 w-fit">
                <Link href={login()}>Sign in</Link>
            </Button>
        </main>
    );
}

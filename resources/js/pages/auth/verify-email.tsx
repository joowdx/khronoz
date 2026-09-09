import { Form } from '@inertiajs/react';
import { destroy } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { store } from '@/actions/App/Http/Controllers/Auth/EmailVerificationNotificationController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { CircleCheckIcon } from 'lucide-react';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <AuthLayout
            title="Verify your email"
            description="We sent a link to your email address. Follow it to confirm the address, then come back here."
        >
            {status && (
                <Alert variant="positive" className="mb-5">
                    <CircleCheckIcon />
                    <span>{status}</span>
                </Alert>
            )}

            <div className="flex flex-col gap-2.5">
                <Form {...store.form()}>
                    {({ processing }) => (
                        <Button type="submit" variant="outline" className="h-11 w-full lg:h-9" disabled={processing}>
                            Send the link again
                        </Button>
                    )}
                </Form>
                <Form {...destroy.form()}>
                    {({ processing }) => (
                        <Button type="submit" variant="ghost" className="h-11 w-full lg:h-9" disabled={processing}>
                            Sign out
                        </Button>
                    )}
                </Form>
            </div>
        </AuthLayout>
    );
}

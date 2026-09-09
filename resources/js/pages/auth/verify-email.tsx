import { Form } from '@inertiajs/react';
import { destroy } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { store } from '@/actions/App/Http/Controllers/Auth/EmailVerificationNotificationController';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <AuthLayout title="Verify your email" description="Confirm your email address to continue.">
            <p className="text-muted-foreground text-sm">
                Follow the link we emailed you to confirm your address before you continue.
            </p>
            {status && <p className="text-primary text-sm">{status}</p>}
            <div className="grid gap-2">
                <Form {...store.form()}>
                    {({ processing }) => (
                        <Button type="submit" variant="outline" className="w-full" disabled={processing}>
                            Resend email
                        </Button>
                    )}
                </Form>
                <Form {...destroy.form()}>
                    {({ processing }) => (
                        <Button type="submit" variant="ghost" className="w-full" disabled={processing}>
                            Log out
                        </Button>
                    )}
                </Form>
            </div>
        </AuthLayout>
    );
}

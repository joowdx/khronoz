import { Form, Link } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/PasswordResetLinkController';
import { InputError } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <AuthLayout title="Forgot password" description="Enter your email and we'll send you a link to reset it.">
            {status && <p className="text-primary text-sm">{status}</p>}
            <Form {...store.form()} className="grid gap-4">
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" name="email" type="email" autoComplete="email" autoFocus required />
                            <InputError message={errors.email} />
                        </div>
                        <Button type="submit" disabled={processing}>
                            Send reset link
                        </Button>
                    </>
                )}
            </Form>
            <p className="text-muted-foreground text-center text-sm">
                Remembered your password?{' '}
                <Link href={login()} className="text-foreground hover:text-primary underline underline-offset-4">
                    Sign in
                </Link>
            </p>
        </AuthLayout>
    );
}

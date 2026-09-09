import { Form, Link } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/PasswordResetLinkController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { CircleCheckIcon } from 'lucide-react';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <AuthLayout
            title="Reset your password"
            description="Enter the email address your HR office invited. We'll send a link to set a new one."
        >
            {/* The same neutral confirmation whether or not the address is
                registered — see PasswordResetLinkController::store(). */}
            {status && (
                <Alert variant="positive" className="mb-5">
                    <CircleCheckIcon />
                    <span>{status}</span>
                </Alert>
            )}

            <Form {...store.form()}>
                {({ errors, processing }) => (
                    <>
                        <Field label="Email" htmlFor="email" error={errors.email}>
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="email"
                                    type="email"
                                    autoComplete="username"
                                    autoFocus
                                    required
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                    className="h-11 lg:h-9"
                                />
                            )}
                        </Field>

                        <Button type="submit" className="mt-5 h-11 w-full lg:h-9" disabled={processing}>
                            Send reset link
                        </Button>
                    </>
                )}
            </Form>

            <p className="pt-3.5 text-center">
                <Link href={login()} className="text-acc-text text-sm font-medium underline-offset-2 hover:underline">
                    Back to sign in
                </Link>
            </p>
        </AuthLayout>
    );
}

import { Form } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/NewPasswordController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import AuthLayout from '@/layouts/auth-layout';
import { TriangleAlertIcon } from 'lucide-react';

export default function ResetPassword({ email, token }: { email: string; token: string }) {
    return (
        <AuthLayout title="Set a new password" description={`Choose a new password for ${email}.`}>
            <Form {...store.form()}>
                {({ errors, processing }) => (
                    <>
                        <input type="hidden" name="token" value={token} />
                        <input type="hidden" name="email" value={email} />

                        {/* The broker reports a spent or unknown link under `email`, but
                            that field is hidden here — so it is a form-level failure and
                            belongs above the fields. */}
                        {errors.email && (
                            <Alert variant="destructive" className="mb-5">
                                <TriangleAlertIcon />
                                <span>{errors.email}</span>
                            </Alert>
                        )}

                        <Field
                            label="New password"
                            htmlFor="password"
                            error={errors.password}
                            hint="At least 8 characters."
                        >
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="password"
                                    type="password"
                                    autoComplete="new-password"
                                    autoFocus
                                    required
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                    className="h-11 lg:h-9"
                                />
                            )}
                        </Field>

                        <Field
                            label="Confirm password"
                            htmlFor="password_confirmation"
                            error={errors.password_confirmation}
                            className="mt-6"
                        >
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="password_confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    required
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                    className="h-11 lg:h-9"
                                />
                            )}
                        </Field>

                        <Button type="submit" className="mt-5 h-11 w-full lg:h-9" disabled={processing}>
                            Set new password
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}

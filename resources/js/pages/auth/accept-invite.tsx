import { Form } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import AuthLayout from '@/layouts/auth-layout';

/** "Corazon Dimaano" -> "Corazon". A greeting uses the name someone is called. */
function firstName(name: string): string {
    return name.trim().split(/\s+/)[0] || name;
}

export default function AcceptInvite({ user, action }: { user: { name: string; email: string }; action: string }) {
    return (
        <AuthLayout
            title={`Welcome, ${firstName(user.name)}`}
            description="Set a password to accept your invitation. Your email address is already set."
        >
            {/* The URL carries the invite's signature, so the form must post
                back to this exact address rather than a Wayfinder-built one. */}
            <Form action={action} method="post">
                {({ errors, processing }) => (
                    <>
                        <Field label="Email" htmlFor="email">
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="email"
                                    defaultValue={user.email}
                                    readOnly
                                    autoComplete="username"
                                    className="h-11 lg:h-9"
                                />
                            )}
                        </Field>

                        <Field
                            label="Password"
                            htmlFor="password"
                            error={errors.password}
                            hint="At least 8 characters."
                            className="mt-6"
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
                            Accept invitation
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}

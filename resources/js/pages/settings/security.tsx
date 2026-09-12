import { Form } from '@inertiajs/react';
import { AccountInput } from '@/components/account-input';
import { Button } from '@/components/ui/button';
import AccountLayout from '@/layouts/account-layout';
import { update } from '@/routes/settings/password';

export default function Security() {
    return (
        <AccountLayout title="Security">
            <section className="grid gap-5">
                <h2 className="text-lg font-semibold">Change password</h2>
                <p className="text-muted-foreground text-sm">
                    Keep a password as a recovery option. Changing it signs out your other sessions and cancels any
                    pending email change.
                </p>
                <Form
                    {...update.form()}
                    className="grid gap-5"
                    resetOnSuccess
                    resetOnError={['password', 'password_confirmation']}
                    disableWhileProcessing
                >
                    {({ errors, processing }) => (
                        <>
                            <AccountInput
                                label="New password"
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                required
                                error={errors.password}
                            />
                            <AccountInput
                                label="Confirm new password"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                required
                                error={errors.password_confirmation}
                            />
                            <Button className="w-fit" disabled={processing}>
                                Update password
                            </Button>
                        </>
                    )}
                </Form>
            </section>
        </AccountLayout>
    );
}

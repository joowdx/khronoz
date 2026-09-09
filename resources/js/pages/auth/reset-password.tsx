import { Form } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/NewPasswordController';
import { InputError } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function ResetPassword({ email, token }: { email: string; token: string }) {
    return (
        <AuthLayout title="Set a new password" description={`Choose a new password for ${email}.`}>
            <Form {...store.form()} className="grid gap-4">
                {({ errors, processing }) => (
                    <>
                        <input type="hidden" name="token" value={token} />
                        <input type="hidden" name="email" value={email} />
                        <div className="grid gap-2">
                            <Label htmlFor="password">New password</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                autoFocus
                                required
                            />
                            <InputError message={errors.password} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">Confirm password</Label>
                            <Input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                required
                            />
                            <InputError message={errors.password_confirmation} />
                        </div>
                        <Button type="submit" disabled={processing}>
                            Set new password
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}

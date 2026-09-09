import { Form } from '@inertiajs/react';
import { InputError } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function AcceptInvite({ user, action }: { user: { name: string; email: string }; action: string }) {
    return (
        <AuthLayout title={`Welcome, ${user.name}`} description="Set a password to accept your invitation.">
            {/* The URL carries the invite's signature, so the form must post
                back to this exact address rather than a Wayfinder-built one. */}
            <Form action={action} method="post" className="grid gap-4">
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" type="email" defaultValue={user.email} readOnly disabled />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
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
                            Accept invitation
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}

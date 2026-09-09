import { Form, Link } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { request } from '@/routes/password';
import { InputError } from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function Login({ status, canResetPassword }: { status?: string; canResetPassword: boolean }) {
    return (
        <AuthLayout title="Sign in" description="Use the email your HR office invited.">
            {status && <p className="text-primary text-sm">{status}</p>}
            <Form {...store.form()} resetOnSuccess={['password']} className="grid gap-4">
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" name="email" type="email" autoComplete="email" autoFocus required />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-2">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="password">Password</Label>
                                {canResetPassword && (
                                    <Link
                                        href={request()}
                                        className="text-muted-foreground hover:text-foreground text-sm"
                                    >
                                        Forgot password?
                                    </Link>
                                )}
                            </div>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                required
                            />
                            <InputError message={errors.password} />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox name="remember" /> Keep me signed in
                        </label>
                        <Button type="submit" disabled={processing}>
                            Sign in
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}

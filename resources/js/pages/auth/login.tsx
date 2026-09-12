import { Form, Link } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { request } from '@/routes/password';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import AuthLayout from '@/layouts/auth-layout';
import { CircleCheckIcon, TriangleAlertIcon } from 'lucide-react';

function GoogleMark() {
    return (
        <svg viewBox="0 0 24 24" className="size-4" aria-hidden="true">
            <path
                fill="currentColor"
                d="M12.24 10.285V14.4h6.806c-.275 1.765-2.056 5.174-6.806 5.174-4.095 0-7.439-3.389-7.439-7.574s3.344-7.574 7.439-7.574c2.33 0 3.891.989 4.785 1.849l3.254-3.138C18.189 1.186 15.479 0 12.24 0c-6.635 0-12 5.365-12 12s5.365 12 12 12c6.926 0 11.52-4.869 11.52-11.726 0-.788-.085-1.39-.189-1.989z"
            />
        </svg>
    );
}

function AppleMark() {
    return (
        <svg viewBox="0 0 16 16" className="size-4" aria-hidden="true">
            <path
                fill="currentColor"
                d="M11.05 8.44c.02 1.9 1.67 2.53 1.69 2.54-.01.04-.26.9-.87 1.79-.53.77-1.08 1.53-1.94 1.55-.85.02-1.12-.5-2.09-.5s-1.27.49-2.07.51c-.83.03-1.46-.83-1.99-1.6-1.09-1.57-1.92-4.45-.8-6.39.55-.97 1.54-1.58 2.62-1.6.82-.01 1.6.55 2.09.55.5 0 1.44-.68 2.42-.58.41.02 1.57.15 2.32 1.13-.06.04-1.38.81-1.36 2.4M9.6 3.05c.43-.53.73-1.26.65-1.99-.63.03-1.4.42-1.85.94-.4.47-.76 1.22-.66 1.93.71.06 1.42-.36 1.86-.88"
            />
        </svg>
    );
}

const RESET_PHRASE = 'reset your password';

function FailureBanner({ message, resetHref }: { message: string; resetHref: string }) {
    const at = message.indexOf(RESET_PHRASE);

    return (
        <Alert variant="destructive" className="mb-5">
            <TriangleAlertIcon />
            <span>
                {at === -1 ? (
                    message
                ) : (
                    <>
                        {message.slice(0, at)}
                        <Link href={resetHref} className="text-acc-text font-medium underline-offset-2 hover:underline">
                            {RESET_PHRASE}
                        </Link>
                        {message.slice(at + RESET_PHRASE.length)}
                    </>
                )}
            </span>
        </Alert>
    );
}

export default function Login({ status, canResetPassword }: { status?: string; canResetPassword: boolean }) {
    const resetHref = request().url;

    return (
        <AuthLayout title="Sign in" description="khronoz is invite-only. Use the email address your administrator invited.">
            {status && (
                <Alert variant="positive" className="mb-5">
                    <CircleCheckIcon />
                    <span>{status}</span>
                </Alert>
            )}

            <Form {...store.form()} resetOnError={['password']}>
                {({ errors, processing }) => (
                    <>
                        {errors.form && <FailureBanner message={errors.form} resetHref={resetHref} />}

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

                        <Field label="Password" htmlFor="password" error={errors.password} className="mt-6">
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                    className="h-11 lg:h-9"
                                />
                            )}
                        </Field>

                        <div className="pt-4 pb-5">
                            <label
                                htmlFor="remember"
                                className="inline-flex cursor-pointer items-center gap-2.5 text-sm leading-5"
                            >
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    value="1"
                                    className="size-[22px] lg:size-[18px]"
                                />
                                Keep me signed in
                            </label>
                        </div>

                        <Button type="submit" className="h-11 w-full lg:h-9" disabled={processing}>
                            Sign in
                        </Button>

                        {canResetPassword && (
                            <p className="pt-3.5 text-center">
                                <Link
                                    href={request()}
                                    className="text-acc-text text-sm font-medium underline-offset-2 hover:underline"
                                >
                                    Forgot password?
                                </Link>
                            </p>
                        )}
                    </>
                )}
            </Form>

            <div className="flex items-center gap-3 py-6">
                <span className="bg-border h-px flex-1" />
                <span className="text-muted-foreground text-[13px] leading-[18px]">or</span>
                <span className="bg-border h-px flex-1" />
            </div>

            <div className="flex flex-col gap-2.5">
                <Button
                    type="button"
                    variant="outline"
                    className="h-11 w-full lg:h-9"
                    aria-describedby="sso-note"
                    disabled
                >
                    <GoogleMark />
                    Continue with Google
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="h-11 w-full lg:h-9"
                    aria-describedby="sso-note"
                    disabled
                >
                    <AppleMark />
                    Continue with Apple
                </Button>
                <p id="sso-note" className="text-muted-foreground text-xs leading-4">
                    Single sign-on is not enabled yet.
                </p>
            </div>
        </AuthLayout>
    );
}

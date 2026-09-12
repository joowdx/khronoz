import { Form, Link } from '@inertiajs/react';
import { SecondFactorInput } from '@/components/second-factor-input';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { store } from '@/actions/App/Http/Controllers/Auth/TwoFactorChallengeController';
import { login } from '@/routes';

export default function TwoFactorChallenge() {
    return (
        <AuthLayout
            title="Verify your sign-in"
            description="Enter a code from your authenticator app, or use one of your saved recovery codes."
        >
            <Form
                {...store.form()}
                className="grid gap-5"
                resetOnError={['code', 'recovery_code']}
                disableWhileProcessing
            >
                {({ errors, processing }) => (
                    <>
                        {errors.form && <Alert variant="destructive">{errors.form}</Alert>}
                        <SecondFactorInput errors={errors} />
                        <Button disabled={processing}>Verify and sign in</Button>
                    </>
                )}
            </Form>
            <p className="mt-5 text-center text-sm">
                <Link href={login()} className="text-acc-text hover:underline">
                    Start again
                </Link>
            </p>
        </AuthLayout>
    );
}

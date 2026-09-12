import { PasskeyButton } from '@/components/passkey-button';
import { options, store as verify } from '@/routes/passkeys/confirm';
import { Form, Link } from '@inertiajs/react';
import { SecondFactorInput } from '@/components/second-factor-input';
import { AccountInput } from '@/components/account-input';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { store } from '@/actions/App/Http/Controllers/Auth/ConfirmationController';
import { edit } from '@/routes/settings/profile';

export default function Confirm({
    destination,
    twoFactor,
    hasPasskeys,
}: {
    destination: string;
    twoFactor: boolean;
    hasPasskeys: boolean;
}) {
    return (
        <AuthLayout
            title="Confirm your identity"
            description="Use your password or a device-verified passkey before changing sensitive account settings. Confirmation lasts five minutes."
        >
            <Form {...store.form()} className="grid gap-5" resetOnError={['password']} disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <input type="hidden" name="destination" value={destination} />
                        <AccountInput
                            label="Password"
                            name="password"
                            type="password"
                            autoComplete="current-password"
                            autoFocus
                            required
                            error={errors.password}
                        />
                        {twoFactor && <SecondFactorInput errors={errors} />}
                        <Button disabled={processing}>Continue</Button>
                    </>
                )}
            </Form>
            {hasPasskeys && (
                <PasskeyButton
                    optionsUrl={options.url()}
                    submitUrl={verify.url({ query: { destination } })}
                    label="Confirm with a passkey"
                />
            )}
            <p className="mt-5 text-center text-sm">
                <Link href={edit()} className="text-acc-text hover:underline">
                    Back to profile
                </Link>
            </p>
        </AuthLayout>
    );
}

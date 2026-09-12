import { Form, Link, usePage } from '@inertiajs/react';
import { AccountInput } from '@/components/account-input';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AccountLayout from '@/layouts/account-layout';
import { update } from '@/routes/settings/profile';
import { store, destroy } from '@/routes/settings/email';
import { store as resend } from '@/routes/settings/email/notification';
import { confirm } from '@/routes/password';
import type { SharedProps } from '@/types';

export default function Profile({
    pendingEmail,
    emailExpiresAt,
    confirmed,
}: {
    pendingEmail: string | null;
    emailExpiresAt: string | null;
    confirmed: boolean;
}) {
    const { auth } = usePage<SharedProps>().props;
    const user = auth.user!;
    return (
        <AccountLayout title="Profile">
            <Form {...update.form()} className="grid gap-5" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <AccountInput
                            label="Name"
                            name="name"
                            defaultValue={user.name}
                            autoComplete="name"
                            maxLength={120}
                            required
                            error={errors.name}
                        />
                        <Button className="w-fit" disabled={processing}>
                            Save name
                        </Button>
                    </>
                )}
            </Form>
            <section className="grid gap-5 border-t pt-8">
                <h2 className="text-lg font-semibold">Login email</h2>
                <p className="text-sm">
                    Your current address is <span className="font-medium break-all">{user.email}</span>.
                </p>
                <p className="text-muted-foreground text-sm">
                    Your current email stays active until you verify a replacement. This does not change your employee
                    record.
                </p>
                {!confirmed ? (
                    <Button asChild variant="outline" className="w-fit">
                        <Link href={confirm({ query: { return: '/settings/profile' } })}>
                            Confirm identity to change email
                        </Link>
                    </Button>
                ) : (
                    <>
                        {pendingEmail && (
                            <Alert>
                                <div className="grid gap-3">
                                    <p>
                                        Waiting for verification:{' '}
                                        <span className="font-medium break-all">{pendingEmail}</span>.
                                    </p>
                                    {emailExpiresAt && (
                                        <p className="text-xs">
                                            The link expires one hour after it is sent. Request a new link if it has
                                            expired.
                                        </p>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        <Form {...resend.form()} disableWhileProcessing>
                                            <Button variant="outline" size="sm">
                                                Resend link
                                            </Button>
                                        </Form>
                                        <Form {...destroy.form()} disableWhileProcessing>
                                            <Button variant="outline" size="sm">
                                                Cancel change
                                            </Button>
                                        </Form>
                                    </div>
                                </div>
                            </Alert>
                        )}
                        <Form {...store.form()} className="grid gap-5" resetOnSuccess disableWhileProcessing>
                            {({ errors, processing }) => (
                                <>
                                    <AccountInput
                                        label="New email"
                                        name="email"
                                        type="email"
                                        autoComplete="email"
                                        required
                                        error={errors.email}
                                    />
                                    <Button variant="outline" className="w-fit" disabled={processing}>
                                        Send verification link
                                    </Button>
                                </>
                            )}
                        </Form>
                    </>
                )}
            </section>
        </AccountLayout>
    );
}

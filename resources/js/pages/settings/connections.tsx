import { Form, Link } from '@inertiajs/react';
import AccountLayout from '@/layouts/account-layout';
import { Button } from '@/components/ui/button';
import { index, store, destroy } from '@/routes/settings/connections';
import { confirm } from '@/routes/password';
import type { Identity, SocialProviders } from '@/types';

export default function Connections({
    connections,
    providers,
    confirmed,
}: {
    connections: Identity[];
    providers: SocialProviders;
    confirmed: boolean;
}) {
    return (
        <AccountLayout title="Connected accounts">
            <p className="text-muted-foreground text-sm">
                Connect Google or Apple to sign in to this Khronoz account. Connections do not change your login email,
                agency, or permissions.
            </p>
            {!confirmed && (
                <Button asChild className="w-fit">
                    <Link href={confirm({ query: { return: index.url() } })}>
                        Confirm your identity to manage connections
                    </Link>
                </Button>
            )}
            {(['google', 'apple'] as const).map((provider) => {
                const connection = connections.find((identity) => identity.provider === provider);
                const label = provider === 'google' ? 'Google' : 'Apple';
                if (!connection && !providers[provider]) return null;
                return (
                    <section key={provider} className="grid gap-4 rounded-lg border p-5">
                        <h2 className="text-lg font-semibold">{label}</h2>
                        {connection ? (
                            <>
                                <p className="text-sm break-words">
                                    {connection.email ?? 'Connected account (email not provided)'}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    Connected{' '}
                                    {new Date(connection.created_at).toLocaleDateString('en-PH', {
                                        timeZone: 'Asia/Manila',
                                    })}
                                </p>
                                {!providers[provider] && (
                                    <p className="text-muted-foreground text-sm">
                                        Sign-in with {label} is currently unavailable. You can still disconnect this
                                        account.
                                    </p>
                                )}
                                <Form {...destroy.form(connection.id)} disableWhileProcessing>
                                    {({ processing }) => (
                                        <Button variant="outline" disabled={!confirmed || processing}>
                                            Disconnect {label}
                                        </Button>
                                    )}
                                </Form>
                                <p className="text-muted-foreground text-xs">
                                    This removes the Khronoz connection. Manage permissions inside {label} separately.
                                </p>
                            </>
                        ) : (
                            <Form {...store.form(provider)} disableWhileProcessing>
                                {({ processing }) => (
                                    <Button variant="outline" disabled={!confirmed || processing}>
                                        Connect {label}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </section>
                );
            })}
            {!providers.google && !providers.apple && connections.length === 0 && (
                <p className="text-muted-foreground text-sm">
                    Social sign-in is not configured. You can use your password or passkeys.
                </p>
            )}
        </AccountLayout>
    );
}

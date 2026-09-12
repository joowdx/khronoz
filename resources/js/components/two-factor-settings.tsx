import { Form, router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { AccountInput } from '@/components/account-input';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { show, store, destroy, confirm } from '@/routes/settings/two-factor';
import { index, store as regenerate } from '@/routes/settings/recovery-codes';
import { confirm as reauthenticate } from '@/routes/password';

export interface TwoFactorState {
    enabled: boolean;
    pending: boolean;
}

export function TwoFactorSettings({ state }: { state: TwoFactorState }) {
    const [setup, setSetup] = useState<{ secret: string; qr: string } | null>(null);
    const [codes, setCodes] = useState<string[] | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    function failed(reason: unknown) {
        if (axios.isAxiosError(reason) && reason.response?.status === 423) {
            router.visit(reauthenticate({ query: { return: '/settings/security' } }));
        } else {
            setError('Unable to load security details. Please try again.');
        }
    }
    useEffect(() => {
        setSetup(null);
        if (!state.pending) return;
        const controller = new AbortController();
        axios
            .get(show.url(), { signal: controller.signal })
            .then(({ data }) => setSetup(data))
            .catch((reason) => {
                if (!axios.isCancel(reason)) failed(reason);
            });
        return () => controller.abort();
    }, [state.pending]);
    async function revealCodes() {
        setError('');
        setLoading(true);
        try {
            const { data } = await axios.get<{ codes: string[] }>(index.url());
            setCodes(data.codes);
        } catch (reason) {
            failed(reason);
        } finally {
            setLoading(false);
        }
    }
    return (
        <section className="grid gap-5 border-t pt-8">
            <h2 className="text-lg font-semibold">Two-factor authentication</h2>
            <p className="text-muted-foreground text-sm">
                {state.enabled
                    ? 'Enabled. Password and social sign-ins also require an authenticator code. A device-verified passkey is sufficient on its own.'
                    : 'Add an authenticator app to protect password and social sign-ins.'}
            </p>
            {error && <Alert variant="destructive">{error}</Alert>}
            {!state.enabled && !state.pending && (
                <Form {...store.form()} disableWhileProcessing>
                    <Button variant="outline">Set up authenticator app</Button>
                </Form>
            )}
            {state.pending && (
                <>
                    {setup ? (
                        <>
                            <div
                                className="w-fit rounded-lg bg-white p-4"
                                role="img"
                                aria-label="Scan this QR code with your authenticator app"
                                dangerouslySetInnerHTML={{ __html: setup.qr }}
                            />
                            <p className="text-sm">
                                Or enter this setup key:{' '}
                                <code className="block break-all select-all">{setup.secret}</code>
                            </p>
                        </>
                    ) : (
                        <p className="text-muted-foreground text-sm">Loading setup details…</p>
                    )}
                    <Form {...confirm.form()} className="grid gap-5" resetOnError={['code']} disableWhileProcessing>
                        {({ errors, processing }) => (
                            <>
                                <AccountInput
                                    label="Authenticator code"
                                    name="code"
                                    autoComplete="one-time-code"
                                    inputMode="numeric"
                                    pattern="[0-9]{6}"
                                    maxLength={6}
                                    required
                                    error={errors.code}
                                />
                                <Button variant="outline" className="w-fit" disabled={processing}>
                                    Confirm and enable
                                </Button>
                            </>
                        )}
                    </Form>
                </>
            )}
            {state.enabled && (
                <div className="grid gap-4">
                    <p className="text-sm">
                        Save your recovery codes somewhere safe. Each works once if you lose access to your
                        authenticator.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={loading}
                            onClick={() => (codes ? setCodes(null) : void revealCodes())}
                        >
                            {codes ? 'Hide recovery codes' : 'Show recovery codes'}
                        </Button>
                        <Form
                            {...regenerate.form()}
                            onSuccess={() => {
                                setCodes(null);
                                void revealCodes();
                            }}
                            disableWhileProcessing
                        >
                            <Button variant="outline">Replace recovery codes</Button>
                        </Form>
                    </div>
                    {codes && (
                        <div className="bg-muted rounded-lg p-4">
                            <ul className="grid gap-2 sm:grid-cols-2" aria-label="Recovery codes">
                                {codes.map((code) => (
                                    <li key={code}>
                                        <code className="select-all">{code}</code>
                                    </li>
                                ))}
                            </ul>
                            {codes.length === 0 && <p>No recovery codes remain. Generate a new set.</p>}
                        </div>
                    )}
                </div>
            )}
            {(state.pending || state.enabled) && (
                <Form {...destroy.form()} onSuccess={() => setCodes(null)} disableWhileProcessing>
                    <Button variant="outline">
                        {state.pending ? 'Cancel setup' : 'Disable two-factor authentication'}
                    </Button>
                </Form>
            )}
        </section>
    );
}

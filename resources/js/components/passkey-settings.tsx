import { useState } from 'react';
import { Form, router } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { AccountInput } from '@/components/account-input';
import { Button } from '@/components/ui/button';
import { options, store, update, destroy } from '@/routes/settings/passkeys';
import type { Passkey } from '@/types';

export function PasskeySettings({ passkeys }: { passkeys: Passkey[] }) {
    const [name, setName] = useState('');
    const { register, isLoading, isSupported, error } = usePasskeyRegister({
        routes: { options: options.url(), submit: store.url() },
        onSuccess: () => {
            setName('');
            router.reload();
        },
    });
    return (
        <section className="grid gap-5 border-t pt-7">
            <h2 className="text-lg font-semibold">Passkeys</h2>
            <p className="text-muted-foreground text-sm">
                Use your device’s screen lock to sign in. Khronoz receives a public key, never your fingerprint, face,
                or device PIN.
            </p>
            <form
                className="grid gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    void register(name);
                }}
            >
                <AccountInput
                    label="New passkey name"
                    name="passkey_name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    placeholder="Personal phone"
                    required
                    maxLength={100}
                />
                <Button className="w-fit" disabled={!isSupported || isLoading}>
                    {isLoading ? 'Waiting for your device…' : 'Add passkey'}
                </Button>
                {!isSupported && (
                    <p className="text-muted-foreground text-sm">
                        Passkeys require a supported browser and a secure connection.
                    </p>
                )}
                {error && (
                    <p role="alert" className="text-destructive text-sm">
                        {error} You can try again.
                    </p>
                )}
            </form>
            {passkeys.length === 0 && <p className="text-muted-foreground text-sm">No passkeys added yet.</p>}
            {passkeys.map((passkey) => (
                <div key={passkey.id} className="grid gap-3 rounded-lg border p-4">
                    <p className="text-muted-foreground text-xs">
                        Added {new Date(passkey.created_at).toLocaleDateString('en-PH', { timeZone: 'Asia/Manila' })} ·{' '}
                        {passkey.last_used_at
                            ? `Last used ${new Date(passkey.last_used_at).toLocaleDateString('en-PH', { timeZone: 'Asia/Manila' })}`
                            : 'Never used'}
                    </p>
                    <Form {...update.form(passkey.id)} className="grid gap-3" disableWhileProcessing>
                        {({ errors, processing }) => (
                            <>
                                <AccountInput
                                    label="Passkey name"
                                    name="name"
                                    id={`passkey-${passkey.id}`}
                                    defaultValue={passkey.name}
                                    required
                                    maxLength={100}
                                    error={errors.name}
                                />
                                <Button variant="outline" className="w-fit" disabled={processing}>
                                    Rename
                                </Button>
                            </>
                        )}
                    </Form>
                    <Form {...destroy.form(passkey.id)} disableWhileProcessing>
                        {({ processing }) => (
                            <Button variant="destructive" disabled={processing}>
                                Remove {passkey.name}
                            </Button>
                        )}
                    </Form>
                </div>
            ))}
        </section>
    );
}

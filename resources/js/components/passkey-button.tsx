import { usePasskeyVerify } from '@laravel/passkeys/react';
import { router } from '@inertiajs/react';
import { FingerprintIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function PasskeyButton({
    optionsUrl,
    submitUrl,
    label,
    remember = false,
}: {
    optionsUrl: string;
    submitUrl: string;
    label: string;
    remember?: boolean | (() => boolean);
}) {
    const { verify, isLoading, isSupported, error } = usePasskeyVerify({
        routes: { options: optionsUrl, submit: submitUrl },
        remember,
        onSuccess: ({ redirect }) => router.visit(redirect ?? '/dashboard'),
    });
    return (
        <div className="mt-5 grid gap-2">
            <Button type="button" variant="outline" disabled={!isSupported || isLoading} onClick={() => void verify()}>
                <FingerprintIcon />
                {isLoading ? 'Waiting for your device…' : label}
            </Button>
            {!isSupported && (
                <p className="text-muted-foreground text-xs">
                    Passkeys need a supported browser and a secure connection. You can use your password instead.
                </p>
            )}
            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error} You can try again or use your password.
                </p>
            )}
        </div>
    );
}

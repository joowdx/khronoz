import { useState } from 'react';
import { AccountInput } from '@/components/account-input';
import { Button } from '@/components/ui/button';

export function SecondFactorInput({ errors }: { errors: Record<string, string> }) {
    const [recovery, setRecovery] = useState(false);
    return (
        <div className="grid gap-3">
            {recovery ? (
                <AccountInput
                    key="recovery"
                    label="Recovery code"
                    name="recovery_code"
                    autoComplete="off"
                    required
                    maxLength={100}
                    error={errors.recovery_code}
                />
            ) : (
                <AccountInput
                    key="code"
                    label="Authenticator code"
                    name="code"
                    autoComplete="one-time-code"
                    inputMode="numeric"
                    pattern="[0-9]{6}"
                    maxLength={6}
                    required
                    error={errors.code}
                />
            )}
            <Button type="button" variant="link" className="w-fit p-0" onClick={() => setRecovery(!recovery)}>
                {recovery ? 'Use an authenticator code' : 'Use a recovery code'}
            </Button>
        </div>
    );
}

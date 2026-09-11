import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCan } from '@/hooks/use-can';
import { lock, unlock } from '@/routes/ledgers';
import type { Ledger } from '@/types';

/**
 * Lock or Unlock for one ledger, gated on `ledgers.manage`.
 *
 * Lock is the ordinary end of a month and needs no confirmation. Unlock
 * reopens frozen numbers, so it goes behind a dialog whose title asks, whose
 * body names that consequence, and whose confirm button repeats the verb
 * (§6.4 / §5.16).
 */
export function LedgerLockControl({ ledger }: { ledger: Ledger }) {
    const can = useCan();

    if (!can('ledgers.manage')) {
        return null;
    }

    if (ledger.locked_at !== null) {
        return <UnlockButton ledger={ledger} />;
    }

    return (
        <Form {...lock.form(ledger)} options={{ preserveScroll: true }} className="inline">
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    disabled={processing}
                    onClick={(event) => event.stopPropagation()}
                >
                    Lock
                </Button>
            )}
        </Form>
    );
}

function UnlockButton({ ledger }: { ledger: Ledger }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button
                type="button"
                variant="destructive"
                size="sm"
                onClick={(event) => {
                    event.stopPropagation();
                    setOpen(true);
                }}
            >
                Unlock
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-[420px]" showCloseButton={false}>
                    <DialogHeader>
                        <DialogTitle>Unlock this ledger?</DialogTitle>
                        <DialogDescription>
                            Unlocking reopens frozen numbers, so a later recompute can move what this month already
                            locked.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...unlock.form(ledger)}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setOpen(false)}
                        disableWhileProcessing
                    >
                        {({ processing }) => (
                            <DialogFooter className="pt-2">
                                <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" variant="destructive" disabled={processing}>
                                    Unlock ledger
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

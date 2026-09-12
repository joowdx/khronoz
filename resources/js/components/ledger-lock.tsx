import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { FormErrors } from '@/components/form-errors';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { unlock } from '@/routes/ledgers';
import type { Ledger } from '@/types';
export function LedgerLockControl({ ledger, allowed }: { ledger: Ledger; allowed: boolean }) {
    const [open, setOpen] = useState(false);
    if (!allowed || ledger.unlocked_at) return null;
    return (
        <>
            <Button type="button" variant="destructive" onClick={() => setOpen(true)}>
                Unlock ledger
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Unlock this ledger?</DialogTitle>
                        <DialogDescription>
                            Workdays can be recomputed after unlocking. This revision remains available, and locking
                            again creates a new revision.
                        </DialogDescription>
                    </DialogHeader>
                    <Form {...unlock.form(ledger)} onSuccess={() => setOpen(false)} disableWhileProcessing>
                        {({ errors, processing }) => (
                            <div className="grid gap-4">
                                <FormErrors errors={errors} />
                                <DialogFooter>
                                    <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" variant="destructive" disabled={processing}>
                                        Unlock ledger
                                    </Button>
                                </DialogFooter>
                            </div>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

import { Form, Link } from '@inertiajs/react';
import { CadenceFields } from '@/components/cadence-fields';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/cadences';
import type { Cadence, Choice } from '@/types';
export default function Edit({ cadence, kinds }: { cadence: Cadence; kinds: Choice[] }) {
    return (
        <AppLayout>
            <PageHeader
                title={cadence.name}
                breadcrumb={{ title: 'Cadences', href: index.url() }}
                description="Changes apply to future ledger locks. Recorded periods stay fixed."
            />
            {cadence.retired_at ? (
                <Alert>
                    This cadence is retired. Its existing assignments and historical ledgers remain available.
                </Alert>
            ) : (
                <Form {...update.form(cadence)} className="grid max-w-[560px] gap-8" disableWhileProcessing>
                    {({ errors, processing }) => (
                        <>
                            <CadenceFields cadence={cadence} kinds={kinds} errors={errors} />
                            <div className="flex gap-3">
                                <Button type="submit" disabled={processing}>
                                    Save changes
                                </Button>
                                <Button asChild variant="ghost">
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </AppLayout>
    );
}

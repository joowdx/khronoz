import { Form, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { SuspensionFields } from '@/components/suspension-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/suspensions';
import type { Suspension, Workgroup } from '@/types';

export default function Edit({
    suspension,
    workgroups,
}: {
    suspension: Suspension;
    workgroups: Workgroup[];
}) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Suspensions', href: index().url }}
                title={suspension.reason}
                description="Who it covered, when, and the paper behind it."
            />
            <Form {...update.form(suspension)} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <SuspensionFields suspension={suspension} workgroups={workgroups} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                Save changes
                            </Button>
                            <Button variant="ghost" asChild>
                                <Link href={index()}>Cancel</Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </AppLayout>
    );
}

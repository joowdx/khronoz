import { Form, Link } from '@inertiajs/react';
import { CadenceFields } from '@/components/cadence-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/cadences';
import type { Choice } from '@/types';
export default function Create({ kinds }: { kinds: Choice[] }) {
    return (
        <AppLayout>
            <PageHeader
                title="Add cadence"
                breadcrumb={{ title: 'Cadences', href: index.url() }}
                description="Choose how your agency divides time records into periods."
            />
            <Form {...store.form()} className="grid max-w-[560px] gap-8" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <CadenceFields kinds={kinds} errors={errors} />
                        <div className="flex gap-3">
                            <Button type="submit" disabled={processing}>
                                Add cadence
                            </Button>
                            <Button asChild variant="ghost">
                                <Link href={index()}>Cancel</Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </AppLayout>
    );
}

import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { HolidayFields } from '@/components/holiday-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/holidays';
import type { Choice } from '@/types';

export default function Create({ rates }: { rates: Choice[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Holidays', href: index().url }}
                title="Add holiday"
                description="A date your agency declares for itself. National holidays arrive already listed."
            />
            <Form {...store.form()} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <HolidayFields rates={rates} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Add holiday
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

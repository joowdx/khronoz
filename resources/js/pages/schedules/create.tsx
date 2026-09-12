import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { ScheduleFields } from '@/components/schedule-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/schedules';
import type { Shift } from '@/types';

export default function Create({ shifts }: { shifts: Shift[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Schedules', href: index().url }}
                title="Add schedule"
                description="A repeating cycle of shifts. Its days are written with it, in one go."
            />
            <Form {...store.form()} className="w-[760px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <ScheduleFields shifts={shifts} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Add schedule
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

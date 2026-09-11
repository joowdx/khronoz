import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { ScheduleFields } from '@/components/schedule-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/schedules';
import type { Shift } from '@/types';

/**
 * Wider than the 560px form column the rest of the product uses, because the
 * cycle is a strip and a strip wants the room — at 760 a fortnight lays out
 * five across, so a seven-day week reads as one block rather than a list.
 */
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

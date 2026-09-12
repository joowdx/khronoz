import { Form, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { ScheduleFields } from '@/components/schedule-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/schedules';
import type { Schedule, Shift } from '@/types';

export default function Edit({ schedule, shifts }: { schedule: Schedule; shifts: Shift[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Schedules', href: index().url }}
                title={schedule.name}
                description="Its cycle, and what a compressed week reverts to."
            />
            <Form {...update.form(schedule)} className="w-[760px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <ScheduleFields schedule={schedule} shifts={shifts} errors={errors} />
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

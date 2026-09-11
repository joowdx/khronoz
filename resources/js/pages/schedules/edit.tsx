import { Form, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { ScheduleFields } from '@/components/schedule-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/schedules';
import type { Schedule, Shift } from '@/types';

/**
 * Editing a schedule changes future computation only — a workday keeps a
 * snapshot of the shift it was computed against (04-scheduling.md rule 2), so
 * rewriting a cycle never moves a figure somebody has already signed.
 *
 * The length and the days submit together, because `turns_complete` counts
 * them together at COMMIT: shortening the cycle drops its tail and widening it
 * repeats what is there, both in the one transaction the controller opens.
 */
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

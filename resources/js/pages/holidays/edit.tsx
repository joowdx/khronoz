import { Form, Link } from '@inertiajs/react';
import { HolidayFields } from '@/components/holiday-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/holidays';
import type { Choice, Holiday } from '@/types';

export default function Edit({ holiday, rates }: { holiday: Holiday; rates: Choice[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Holidays', href: index().url }}
                title={holiday.name}
                description="When it falls, what it is worth, and the paper that declared it."
            />
            <Form {...update.form(holiday)} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <HolidayFields holiday={holiday} rates={rates} errors={errors} />
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

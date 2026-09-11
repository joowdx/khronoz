import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { OvertimeFields } from '@/components/overtime-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/overtimes';
import type { Choice, Employee } from '@/types';

export default function Create({ employees, modes }: { employees: Employee[]; modes: Choice[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Overtime', href: index().url }}
                title="Authorise overtime"
                description="Work beyond the shift, authorised in advance. It may run past midnight."
            />
            <Form {...store.form()} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <OvertimeFields employees={employees} modes={modes} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Authorise overtime
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

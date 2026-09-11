import { Form, Link } from '@inertiajs/react';
import { OvertimeFields } from '@/components/overtime-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/overtimes';
import type { Choice, Employee, Overtime } from '@/types';

export default function Edit({
    overtime,
    employees,
    modes,
}: {
    overtime: Overtime;
    employees: Employee[];
    modes: Choice[];
}) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Overtime', href: index().url }}
                title={overtime.employee?.name ?? 'Overtime'}
                description="Which hours, what for, and how it is compensated."
            />
            <Form {...update.form(overtime)} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <OvertimeFields overtime={overtime} employees={employees} modes={modes} errors={errors} />
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

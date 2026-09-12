import { Form, Link } from '@inertiajs/react';
import { EmployeeFields } from '@/components/employee-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, show, update } from '@/routes/employees';
import type { Cadence, Employee } from '@/types';

export default function Edit({ employee, cadences }: { employee: Employee; cadences: Cadence[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Employees', href: index().url }}
                title={employee.name}
                description="Their record. Where they work is a deployment, changed from their profile."
            />
            <Form {...update.form(employee)} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <EmployeeFields employee={employee} cadences={cadences} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                Save changes
                            </Button>
                            <Button variant="ghost" asChild>
                                <Link href={show(employee)}>Cancel</Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </AppLayout>
    );
}

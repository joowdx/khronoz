import { Form, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { WorkgroupFields } from '@/components/workgroup-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/workgroups';
import type { Employee, Workgroup } from '@/types';

export default function Edit({
    workgroup,
    workgroups,
    employees,
}: {
    workgroup: Workgroup;
    workgroups: Workgroup[];
    employees: Employee[];
}) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Workgroups', href: index().url }}
                title={workgroup.name}
                description="Where it sits, what it is called, and who runs it."
            />
            <Form {...update.form(workgroup)} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <WorkgroupFields workgroup={workgroup} workgroups={workgroups} employees={employees} errors={errors} />
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

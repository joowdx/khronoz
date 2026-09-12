import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { EmployeeFields } from '@/components/employee-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { Choice } from '@/types';
import { index, store } from '@/routes/employees';

export default function Create({ sexes }: { sexes: Choice[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Employees', href: index().url }}
                title="Add employee"
                description="Someone this agency files a daily time record for. Deploy them to a workgroup next, from their profile."
            />
            <Form {...store.form()} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <EmployeeFields sexes={sexes} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Add employee
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

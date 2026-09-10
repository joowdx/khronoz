import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { EmployeeFields } from '@/components/employee-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/employees';

/**
 * One 560px column: the person, then how to reach them, then their
 * employment, then the submit row (§6.1). Nothing here is a card — a form is
 * not a container.
 *
 * The workgroup is deliberately absent. Where someone works is a `Deployment`, a
 * dated placement with a history of its own, so it is created from the
 * employee's profile rather than folded into this form as if it were another
 * column of `employees`. The description says so, because otherwise the first
 * thing a new user looks for here is the workgroup.
 */
export default function Create() {
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
                        <EmployeeFields errors={errors} />
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

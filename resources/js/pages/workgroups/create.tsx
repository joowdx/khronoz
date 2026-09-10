import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { WorkgroupFields } from '@/components/workgroup-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/workgroups';
import type { Employee, Workgroup } from '@/types';

/**
 * One 560px column, five fields, the submit row (§6.1). Not in the brief's
 * table of screens, but `WorkgroupController::create` renders it and the workgroups tree
 * links to it — a primary action that lands on a missing page is the exact
 * failure §10 and components.md both forbid, and the milestone's own exit
 * criterion is to build an agency's tree by hand in this UI.
 */
export default function Create({ workgroups, employees }: { workgroups: Workgroup[]; employees: Employee[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Workgroups', href: index().url }}
                title="Add workgroup"
                description="A box on your org chart. Employees are deployed into it, and it can hold workgroups of its own."
            />
            <Form {...store.form()} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <WorkgroupFields workgroups={workgroups} employees={employees} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Add workgroup
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

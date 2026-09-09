import { Form, Link } from '@inertiajs/react';
import { EmployeeFields } from '@/components/employee-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, show, update } from '@/routes/employees';
import type { Employee } from '@/types';

/**
 * The add form's column and fields again, against a person who already
 * exists — so the title is their name and the primary action is Save changes.
 *
 * Cancel goes back to their profile, not to the list: this screen is reached
 * from the profile as often as from the row menu, and the profile is where
 * everything the form does not hold — the deployment history — is read.
 */
export default function Edit({ employee }: { employee: Employee }) {
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
                        <EmployeeFields employee={employee} errors={errors} />
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

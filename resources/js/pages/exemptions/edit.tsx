import { Form, Link } from '@inertiajs/react';
import { ExemptionFields } from '@/components/exemption-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/exemptions';
import type { Choice, Employee, Exemption } from '@/types';

export default function Edit({
    exemption,
    employees,
    types,
}: {
    exemption: Exemption;
    employees: Employee[];
    types: Choice[];
}) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Exemptions', href: index().url }}
                title={exemption.employee?.name ?? 'Exemption'}
                description="Who it covers, which days, and the paper behind it."
            />
            <Form {...update.form(exemption)} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <ExemptionFields exemption={exemption} employees={employees} types={types} errors={errors} />
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

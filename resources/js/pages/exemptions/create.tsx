import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { ExemptionFields } from '@/components/exemption-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/exemptions';
import type { Choice, Employee } from '@/types';

export default function Create({ employees, types }: { employees: Employee[]; types: Choice[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Exemptions', href: index().url }}
                title="Record an exemption"
                description="Leave, official business, a pass slip — one row covers the whole run of days it was granted for."
            />
            <Form {...store.form()} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <ExemptionFields employees={employees} types={types} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Record exemption
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

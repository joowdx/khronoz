import { Form, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { UnitFields } from '@/components/unit-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/units';
import type { Employee, Unit } from '@/types';

/**
 * The add form again, against a unit that already exists — so the title is its
 * name and the primary action is Save changes. The parent picker drops this
 * unit and everything under it (see `UnitFields`), which is what keeps the
 * `units_acyclic` trigger from ever having to speak.
 */
export default function Edit({
    unit,
    units,
    employees,
}: {
    unit: Unit;
    units: Unit[];
    employees: Employee[];
}) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Units', href: index().url }}
                title={unit.name}
                description="Where it sits, what it is called, and who runs it."
            />
            <Form {...update.form(unit)} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <UnitFields unit={unit} units={units} employees={employees} errors={errors} />
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

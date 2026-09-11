import { Form, Link } from '@inertiajs/react';
import { ShiftFields } from '@/components/shift-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/shifts';
import type { Shift } from '@/types';

export default function Edit({ shift, usedColors }: { shift: Shift; usedColors: number[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Shifts', href: index().url }}
                title={shift.name}
                description="Edit the day template's fields, slots and colour."
            />
            <Form {...update.form(shift)} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <ShiftFields shift={shift} usedColors={usedColors} errors={errors} />
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

import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { ShiftFields } from '@/components/shift-fields';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/shifts';

export default function Create({ usedColors }: { usedColors: number[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Shifts', href: index().url }}
                title="Add shift"
                description="A day template the scheduler assigns to turns in a roster cycle."
            />
            <Form {...store.form()} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <ShiftFields usedColors={usedColors} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Add shift
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

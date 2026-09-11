import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { SuspensionFields } from '@/components/suspension-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/suspensions';
import type { Workgroup } from '@/types';

export default function Create({ workgroups }: { workgroups: Workgroup[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Suspensions', href: index().url }}
                title="Declare a suspension"
                description="A day, or part of one, on which work was called off."
            />
            <Form {...store.form()} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <SuspensionFields workgroups={workgroups} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Declare suspension
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

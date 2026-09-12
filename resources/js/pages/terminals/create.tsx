import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { TerminalFields } from '@/components/terminal-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/terminals';
import type { Choice, Workgroup } from '@/types';

export default function Create({
    workgroups,
    kinds,
    protocols,
}: {
    workgroups: Workgroup[];
    kinds: Choice[];
    protocols: Choice[];
}) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Terminals', href: index().url }}
                title="Register terminal"
                description="A biometric device. Its device number is how every file it exports says where it came from, so it has to match what the device itself reports."
            />
            <Form {...store.form()} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <TerminalFields workgroups={workgroups} kinds={kinds} protocols={protocols} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Register terminal
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

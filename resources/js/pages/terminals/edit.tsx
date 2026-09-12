import { Form, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { TerminalFields } from '@/components/terminal-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/terminals';
import type { Choice, Terminal, Workgroup } from '@/types';

export default function Edit({
    terminal,
    workgroups,
    kinds,
    protocols,
}: {
    terminal: Terminal;
    workgroups: Workgroup[];
    kinds: Choice[];
    protocols: Choice[];
}) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Terminals', href: index().url }}
                title={terminal.name}
                description="What it is called, where it sits, and the device number its files must carry."
            />
            <Form {...update.form(terminal)} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <TerminalFields
                            terminal={terminal}
                            workgroups={workgroups}
                            kinds={kinds}
                            protocols={protocols}
                            errors={errors}
                        />
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

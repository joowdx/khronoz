import { Form, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { TerminalFields } from '@/components/terminal-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/terminals';
import type { Terminal, Workgroup } from '@/types';

/**
 * The add form again, against a terminal that already exists.
 *
 * Changing the device number here is an ordinary correction — a timekeeper
 * who typed `1` for a device that reports `01` has to be able to fix it — and
 * it costs nothing structurally: every punch references `terminals.id`, an
 * immutable ULID, so renumbering rewrites one column on one row and no
 * history moves (TerminalTest covers exactly that). What it *does* change is
 * which files the importer will accept from this terminal, which is why the
 * field carries the hint it does.
 */
export default function Edit({ terminal, workgroups }: { terminal: Terminal; workgroups: Workgroup[] }) {
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
                        <TerminalFields terminal={terminal} workgroups={workgroups} errors={errors} />
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

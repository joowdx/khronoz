import { Form, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { TeamFields } from '@/components/team-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/teams';
import type { Schedule } from '@/types';

export default function Create({ schedules }: { schedules: Schedule[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Teams', href: index().url }}
                title="Add team"
                description="One schedule and one anchor. People join it by being rostered into it."
            />
            <Form {...store.form()} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <TeamFields schedules={schedules} errors={errors} />
                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Add team
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

import { Form, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { TeamFields } from '@/components/team-fields';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/teams';
import type { Schedule, Team } from '@/types';

export default function Edit({ team, schedules }: { team: Team; schedules: Schedule[] }) {
    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Teams', href: index().url }}
                title={team.name}
                description="The schedule this cohort follows, and the day its cycle begins."
            />
            <Form {...update.form(team)} className="w-[560px] max-w-full" disableWhileProcessing>
                {({ errors, processing }) => (
                    <>
                        <TeamFields team={team} schedules={schedules} errors={errors} />
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

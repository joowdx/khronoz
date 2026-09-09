import { Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { index as agenciesIndex } from '@/routes/platform/agencies';
import type { SharedProps } from '@/types';

interface DashboardCounts {
    users: number;
    agencies?: number;
}

export default function Dashboard({ counts }: { counts: DashboardCounts }) {
    const { agency } = usePage<SharedProps>().props;

    const manageAgencies = agency?.platform && (
        <Button asChild variant="outline">
            <Link href={agenciesIndex()}>Manage agencies</Link>
        </Button>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'Dashboard' }]}>
            <PageHeader title="Dashboard" description={agency?.name} actions={manageAgencies} />
            <Card>
                <CardContent>
                    <dl className="divide-border divide-y text-sm">
                        <div className="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                            <dt className="text-muted-foreground">Users</dt>
                            <dd className="font-medium tabular-nums">{counts.users}</dd>
                        </div>
                        {typeof counts.agencies === 'number' && (
                            <div className="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                                <dt className="text-muted-foreground">Agencies</dt>
                                <dd className="font-medium tabular-nums">{counts.agencies}</dd>
                            </div>
                        )}
                    </dl>
                </CardContent>
            </Card>
        </AppLayout>
    );
}

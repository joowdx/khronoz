import { usePage } from '@inertiajs/react';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import type { SharedProps } from '@/types';

// Placeholder: proves SetTenant and the shared `agency` prop render end to
// end for a signed-in user. Task 10 replaces this with the real dashboard.
export default function Dashboard() {
    const { agency } = usePage<SharedProps>().props;

    return (
        <AppLayout breadcrumbs={[{ title: 'Dashboard' }]}>
            <Card>
                <CardHeader>
                    <CardTitle>Dashboard</CardTitle>
                    <CardDescription>Signed in to {agency?.name ?? 'the platform'}.</CardDescription>
                </CardHeader>
            </Card>
        </AppLayout>
    );
}

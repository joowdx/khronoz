import { Form, Link } from '@inertiajs/react';
import { create, edit, enter } from '@/routes/platform/agencies';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { Agency } from '@/types';

export default function Index({ agencies }: { agencies: Agency[] }) {
    const addAgency = (
        <Button asChild>
            <Link href={create()}>Add agency</Link>
        </Button>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'Platform' }, { title: 'Agencies' }]}>
            <PageHeader title="Agencies" actions={addAgency} />
            {agencies.length === 0 ? (
                <EmptyState title="No agencies yet" action={addAgency} />
            ) : (
                <Card>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {agencies.map((agency) => (
                                    <TableRow key={agency.id}>
                                        <TableCell>{agency.code}</TableCell>
                                        <TableCell>{agency.name}</TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-2">
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link href={edit(agency)}>Edit</Link>
                                                </Button>
                                                <Form {...enter.form(agency)}>
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={processing}
                                                        >
                                                            Enter
                                                        </Button>
                                                    )}
                                                </Form>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            )}
        </AppLayout>
    );
}

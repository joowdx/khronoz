import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusPill } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDay } from '@/lib/dates';
import { create, edit, retire } from '@/routes/cadences';
import type { Cadence } from '@/types';
const COLUMNS = { frequency: 150, periods: 240, status: 140, actions: 170 };
const TABLE_WIDTH = Object.values(COLUMNS).reduce((sum, value) => sum + value, 240);
export default function Index({ cadences }: { cadences: Cadence[] }) {
    const [retiring, setRetiring] = useState<Cadence | null>(null);
    return (
        <AppLayout>
            <PageHeader
                title="Cadences"
                description="The recurring date ranges used to lock time records."
                actions={
                    <Button asChild>
                        <Link href={create()}>Add cadence</Link>
                    </Button>
                }
            />
            <Card className="min-w-min overflow-visible">
                {cadences.length === 0 ? (
                    <EmptyState
                        title="No custom cadences"
                        description="The default is a calendar month. Add a cadence for weekly, fortnightly, or different monthly starting days."
                    />
                ) : (
                    <Table style={{ minWidth: TABLE_WIDTH }}>
                        <TableHeader sticky>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead style={{ width: COLUMNS.frequency }}>Frequency</TableHead>
                                <TableHead style={{ width: COLUMNS.periods }}>Period starts</TableHead>
                                <TableHead style={{ width: COLUMNS.status }}>Status</TableHead>
                                <TableHead style={{ width: COLUMNS.actions }}>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {cadences.map((cadence) => (
                                <TableRow key={cadence.id}>
                                    <TableCell className="font-medium">{cadence.name}</TableCell>
                                    <TableCell>{cadence.kind.label}</TableCell>
                                    <TableCell>
                                        {cadence.anchor
                                            ? formatDay(cadence.anchor)
                                            : (cadence.rules.starts ?? [1]).join(' and ') + ' of each month'}
                                    </TableCell>
                                    <TableCell>
                                        {cadence.retired_at ? (
                                            <StatusPill>Retired</StatusPill>
                                        ) : cadence.preferred ? (
                                            <StatusPill variant="positive">Agency default</StatusPill>
                                        ) : (
                                            'Active'
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {!cadence.retired_at && (
                                            <div className="flex justify-end gap-2">
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link href={edit(cadence)}>Edit</Link>
                                                </Button>
                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                    onClick={() => setRetiring(cadence)}
                                                >
                                                    Retire
                                                </Button>
                                            </div>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>
            <Dialog
                open={retiring !== null}
                onOpenChange={(open) => {
                    if (!open) setRetiring(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Retire {retiring?.name}?</DialogTitle>
                        <DialogDescription>
                            New assignments will no longer offer this cadence. Existing assignments and historical
                            ledgers remain available.
                        </DialogDescription>
                    </DialogHeader>
                    {retiring && (
                        <Form {...retire.form(retiring)} onSuccess={() => setRetiring(null)}>
                            {({ processing }) => (
                                <DialogFooter>
                                    <Button type="button" variant="ghost" onClick={() => setRetiring(null)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" variant="destructive" disabled={processing}>
                                        Retire cadence
                                    </Button>
                                </DialogFooter>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

import { Form } from '@inertiajs/react';
import { index, store } from '@/routes/platform/agencies';
import { InputError } from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';

export default function Create() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Platform' }, { title: 'Agencies', href: index().url }, { title: 'Add agency' }]}>
            <PageHeader title="Add agency" />
            <Card>
                <CardContent>
                    <Form {...store.form()} className="grid gap-4">
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="code">Code</Label>
                                    <Input
                                        id="code"
                                        name="code"
                                        className="uppercase"
                                        maxLength={16}
                                        autoFocus
                                        required
                                    />
                                    <p className="text-muted-foreground text-sm">Short and unique, e.g. DOH</p>
                                    <InputError message={errors.code} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input id="name" name="name" maxLength={120} required />
                                    <InputError message={errors.name} />
                                </div>
                                <Button type="submit" disabled={processing} className="justify-self-start">
                                    Create agency
                                </Button>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}

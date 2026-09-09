import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { index, store } from '@/routes/users';
import { InputError } from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { PermissionPicker, type PermissionOption, type PresetOption } from '@/components/permission-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { Permission } from '@/types';

export default function Create({ presets, permissions }: { presets: PresetOption[]; permissions: PermissionOption[] }) {
    const [selected, setSelected] = useState<Permission[]>([]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Users', href: index().url }, { title: 'Invite user' }]}>
            <PageHeader title="Invite user" />
            <Card>
                <CardContent>
                    <Form {...store.form()} className="grid gap-4">
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input id="name" name="name" maxLength={120} autoFocus required />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input id="email" name="email" type="email" maxLength={254} required />
                                    <InputError message={errors.email} />
                                </div>
                                <fieldset className="grid gap-2">
                                    <legend className="text-sm font-medium">Permissions</legend>
                                    <PermissionPicker
                                        value={selected}
                                        onChange={setSelected}
                                        presets={presets}
                                        permissions={permissions}
                                    />
                                    <InputError message={errors.permissions} />
                                </fieldset>
                                <Button type="submit" disabled={processing} className="justify-self-start">
                                    Send invitation
                                </Button>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}

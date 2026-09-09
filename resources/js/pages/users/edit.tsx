import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { index, update } from '@/routes/users';
import { InputError } from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { PermissionPicker, type PermissionOption, type PresetOption } from '@/components/permission-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { Permission, User } from '@/types';

export default function Edit({
    user,
    presets,
    permissions,
}: {
    user: User;
    presets: PresetOption[];
    permissions: PermissionOption[];
}) {
    const [selected, setSelected] = useState<Permission[]>(user.permissions);

    return (
        <AppLayout breadcrumbs={[{ title: 'Users', href: index().url }, { title: user.name }]}>
            <PageHeader title="Edit user" description={user.name} />
            <Card>
                <CardContent>
                    <Form {...update.form(user)} className="grid gap-4">
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={user.name}
                                        maxLength={120}
                                        autoFocus
                                        required
                                    />
                                    <InputError message={errors.name} />
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
                                    Save changes
                                </Button>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}

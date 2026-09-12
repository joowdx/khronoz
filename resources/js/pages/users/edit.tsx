import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { PermissionMatrix, type PresetOption } from '@/components/permission-matrix';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { index, update } from '@/routes/users';
import type { Permission, User } from '@/types';

export default function Edit({ user, presets }: { user: User; presets: PresetOption[] }) {
    const [permissions, setPermissions] = useState<Permission[]>(user.permissions);

    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Users', href: index().url }}
                title={user.name}
                description="What this person can reach. Changes take effect the next time they load a page."
            />
            <Form {...update.form(user)} className="w-[560px] max-w-full">
                {({ errors, processing }) => (
                    <>
                        <Field label="Name" htmlFor="name" error={errors.name}>
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="name"
                                    defaultValue={user.name}
                                    autoFocus
                                    autoComplete="name"
                                    maxLength={120}
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                />
                            )}
                        </Field>
                        <Field
                            className="mt-6"
                            label="Email"
                            htmlFor="email"
                            hint="A sign-in address cannot be changed."
                        >
                            {({ id, describedBy }) => (
                                <Input
                                    id={id}
                                    type="email"
                                    value={user.email}
                                    readOnly
                                    aria-describedby={describedBy}
                                />
                            )}
                        </Field>
                        <PermissionMatrix
                            value={permissions}
                            onChange={setPermissions}
                            presets={presets}
                            error={errors.permissions}
                        />
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

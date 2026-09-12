import { Form, Link } from '@inertiajs/react';
import { SendIcon, TriangleAlertIcon } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { PermissionMatrix, type PresetOption } from '@/components/permission-matrix';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/users';
import type { Permission } from '@/types';

export default function Create({ presets }: { presets: PresetOption[] }) {
    const [permissions, setPermissions] = useState<Permission[]>([]);
    const [email, setEmail] = useState('');

    return (
        <AppLayout>
            <PageHeader
                breadcrumb={{ title: 'Users', href: index().url }}
                title="Invite user"
                description="They receive an email and choose their own password. The invitation expires in 7 days."
            />
            <Form {...store.form()} className="w-[560px] max-w-full">
                {({ errors, processing }) => (
                    <>
                        <Field label="Name" htmlFor="name" error={errors.name}>
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="name"
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
                            error={errors.email}
                            hint={errors.email_conflict ? undefined : 'The invitation goes to this address only.'}
                        >
                            {({ id, invalid, describedBy }) => (
                                <>
                                    <Input
                                        id={id}
                                        name="email"
                                        type="email"
                                        autoComplete="email"
                                        maxLength={254}
                                        value={email}
                                        onChange={(event) => setEmail(event.target.value)}
                                        aria-invalid={invalid}
                                        aria-describedby={
                                            [describedBy, errors.email_conflict ? 'email-conflict' : null]
                                                .filter(Boolean)
                                                .join(' ') || undefined
                                        }
                                    />
                                    {errors.email_conflict && (
                                        <Alert
                                            role={undefined}
                                            id="email-conflict"
                                            variant="destructive"
                                            className="mt-2"
                                        >
                                            <TriangleAlertIcon aria-hidden strokeWidth={1.5} />
                                            <AlertDescription>
                                                {errors.email_conflict}{' '}
                                                <Link
                                                    href={index.url({ query: { search: email } })}
                                                    className="text-acc-text font-medium underline-offset-2 hover:underline"
                                                >
                                                    Find it in Users
                                                </Link>{' '}
                                                to change what they can reach, or invite a different address.
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                </>
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
                                <SendIcon aria-hidden strokeWidth={1.5} />
                                Send invitation
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

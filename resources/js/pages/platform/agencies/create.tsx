import { Form, Link } from '@inertiajs/react';
import { TriangleAlertIcon } from 'lucide-react';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { index, store } from '@/routes/platform/agencies';

/**
 * One 560px column, two fields, one primary action (§6.1). The code is
 * upper-cased on the way in by StoreAgencyRequest, so the `uppercase` class
 * only shows the reader what will be stored rather than fighting them for it.
 */
export default function Create() {
    return (
        <AppLayout>
            <PageHeader
                title="Add agency"
                breadcrumb={{ title: 'Agencies', href: index().url }}
                description="Add the office, then enter it to set up its employees and schedules."
            />

            <Form {...store.form()} className="w-[560px] max-w-full">
                {({ errors, processing }) => (
                    <>
                        {/* A failure that belongs to no field: above the fields,
                            and neither control's border changes (§6.4). */}
                        {errors.form && (
                            <Alert variant="destructive" className="mb-6">
                                <TriangleAlertIcon />
                                <span>{errors.form}</span>
                            </Alert>
                        )}

                        <Field
                            label="Code"
                            htmlFor="code"
                            error={errors.code}
                            hint="Short, unique and upper case. PHO, DOH, LGU-IL."
                        >
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="code"
                                    maxLength={16}
                                    autoFocus
                                    required
                                    autoComplete="off"
                                    autoCapitalize="characters"
                                    spellCheck={false}
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                    className="uppercase"
                                />
                            )}
                        </Field>

                        <Field
                            label="Name"
                            htmlFor="name"
                            error={errors.name}
                            hint="The office's full name, as it appears on documents."
                            className="mt-6"
                        >
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="name"
                                    maxLength={120}
                                    required
                                    autoComplete="off"
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                />
                            )}
                        </Field>

                        <div className="flex gap-3 pt-8">
                            <Button type="submit" disabled={processing}>
                                Add agency
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

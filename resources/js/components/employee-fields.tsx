import { type ReactNode, useState } from 'react';
import { Field } from '@/components/field';
import { ChoiceField } from '@/components/choice-field';
import { TagInput } from '@/components/tag-input';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Cadence, Choice, Employee } from '@/types';

const UNRECORDED = 'unrecorded';

function Pair({ className = 'sm:grid-cols-2', children }: { className?: string; children: ReactNode }) {
    return <div className={`mt-6 grid grid-cols-1 gap-3 ${className}`}>{children}</div>;
}

function Subject({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="border-border mt-8 border-t pt-8">
            <h2 className="text-sm leading-5 font-semibold tracking-[-0.002em]">{title}</h2>
            {children}
        </section>
    );
}

export function EmployeeFields({
    employee,
    sexes,
    cadences,
    errors,
}: {
    employee?: Employee;
    sexes: Choice[];
    cadences: Cadence[];
    errors: Partial<Record<string, string>>;
}) {
    const [sex, setSex] = useState<string>(employee?.sex?.value ?? '');
    const [tags, setTags] = useState<string[]>(employee?.tags ?? []);
    const [exempt, setExempt] = useState<boolean>(employee?.exempt ?? false);
    const [cadence, setCadence] = useState(employee?.cadence_id ?? '');

    return (
        <>
            <Field
                label="Employee number"
                htmlFor="number"
                error={errors.number}
                hint="The number your agency already uses for this person."
            >
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="number"
                        defaultValue={employee?.number}
                        autoFocus
                        maxLength={255}
                        className="tabular-nums sm:w-[220px]"
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>

            <Pair>
                <Field label="First name" htmlFor="first_name" error={errors.first_name}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="first_name"
                            defaultValue={employee?.first_name}
                            autoComplete="given-name"
                            maxLength={255}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field label="Middle name" htmlFor="middle_name" error={errors.middle_name}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="middle_name"
                            defaultValue={employee?.middle_name ?? ''}
                            autoComplete="additional-name"
                            maxLength={255}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </Pair>

            <Pair className="sm:grid-cols-[1fr_140px]">
                <Field label="Last name" htmlFor="last_name" error={errors.last_name}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="last_name"
                            defaultValue={employee?.last_name}
                            autoComplete="family-name"
                            maxLength={255}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field label="Suffix" htmlFor="suffix" error={errors.suffix}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="suffix"
                            defaultValue={employee?.suffix ?? ''}
                            autoComplete="honorific-suffix"
                            placeholder="Jr."
                            maxLength={255}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </Pair>

            <Pair>
                <Field label="Sex" htmlFor="sex" error={errors.sex}>
                    {({ id, invalid, describedBy }) => (
                        <>
                            <input type="hidden" name="sex" value={sex} />
                            <Select
                                value={sex === '' ? UNRECORDED : sex}
                                onValueChange={(value) => setSex(value === UNRECORDED ? '' : value)}
                            >
                                <SelectTrigger
                                    id={id}
                                    className="w-full"
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent position="popper" align="start" sideOffset={6}>
                                    <SelectItem value={UNRECORDED}>Not recorded</SelectItem>
                                    {sexes.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </>
                    )}
                </Field>
                <Field label="Date of birth" htmlFor="birthdate" error={errors.birthdate}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="birthdate"
                            type="date"
                            defaultValue={employee?.birthdate ?? ''}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </Pair>

            <Subject title="Contact">
                <Field className="mt-4" label="Email" htmlFor="email" error={errors.email}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="email"
                            type="email"
                            defaultValue={employee?.email ?? ''}
                            autoComplete="email"
                            maxLength={254}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field
                    className="mt-6"
                    label="Mobile"
                    htmlFor="mobile"
                    error={errors.mobile}
                    hint="Not used to notify anyone yet."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="mobile"
                            type="tel"
                            defaultValue={employee?.mobile ?? ''}
                            autoComplete="tel"
                            placeholder="09XX XXX XXXX"
                            maxLength={20}
                            className="tabular-nums sm:w-[240px]"
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </Subject>

            <Subject title="Employment">
                <div className="mt-4">
                    <ChoiceField
                        label="Ledger cadence"
                        name="cadence_id"
                        value={cadence}
                        onChange={setCadence}
                        choices={cadences.map((item) => ({
                            value: item.id,
                            label: item.name + (item.retired_at ? ' (retired)' : ''),
                        }))}
                        emptyLabel="Agency default"
                        error={errors.cadence_id}
                        hint="Use the agency default unless this employee follows a different period."
                    />
                </div>
                <Field className="mt-4" label="Position" htmlFor="position" error={errors.position}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="position"
                            defaultValue={employee?.position ?? ''}
                            placeholder="Administrative Officer II"
                            maxLength={255}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>

                <Field
                    className="mt-6"
                    label="Tags"
                    htmlFor="tags"
                    error={errors.tags}
                    hint="Your agency's own labels: a ward, a cohort, a job family. You can filter and select people by them."
                >
                    {({ id, invalid, describedBy }) => (
                        <TagInput id={id} value={tags} onChange={setTags} invalid={invalid} describedBy={describedBy} />
                    )}
                </Field>

                <div className="mt-8 flex items-start gap-3">
                    <input type="hidden" name="exempt" value={exempt ? '1' : '0'} />
                    <Checkbox
                        id="exempt"
                        checked={exempt}
                        onCheckedChange={(checked) => setExempt(checked === true)}
                        className="mt-0.5"
                    />
                    <div className="min-w-0">
                        <label htmlFor="exempt" className="text-sm leading-5 font-medium">
                            No daily time record expected
                        </label>
                        <p className="text-muted-foreground pt-1 text-xs leading-4">
                            For the officials and consultants your agency does not require a DTR from. They still appear
                            in the lists, and nothing is computed for them.
                        </p>
                    </div>
                </div>
            </Subject>
        </>
    );
}

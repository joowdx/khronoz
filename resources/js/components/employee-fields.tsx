import { type ReactNode, useState } from 'react';
import { Field } from '@/components/field';
import { TagInput } from '@/components/tag-input';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Employee } from '@/types';

/** Radix refuses an empty `value`, so "not recorded" needs a token of its own; the hidden input turns it back into ''. */
const UNRECORDED = 'unrecorded';

/**
 * A pair of fields on one row: §4.1's `--s3` (12) is "gap between controls in
 * a row", and a first and middle name read as one thing. §6.1's one column is
 * the form's column, not a rule that every field must own a whole line — four
 * stacked name boxes would push the other fields below the fold for no gain.
 */
function Pair({ className = 'sm:grid-cols-2', children }: { className?: string; children: ReactNode }) {
    return <div className={`mt-6 grid grid-cols-1 gap-3 ${className}`}>{children}</div>;
}

/**
 * A new subject inside the form. §6.1 gives a group that starts one 32 of top
 * margin; the rule is §4.1's `--s6` "section gap either side of the rule",
 * which is how every other long surface in this product divides itself
 * (dashboard.tsx's `Stack`). It separates personal details from the position and timekeeping fields.
 */
function Subject({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="border-border mt-8 border-t pt-8">
            <h2 className="text-sm leading-5 font-semibold tracking-[-0.002em]">{title}</h2>
            {children}
        </section>
    );
}

/**
 * Every column of `employees`, in one 560px column — shared by the add and the
 * edit screens because they are the same fields against the same
 * rules, and a second copy would drift the moment one rule changes.
 *
 * Three things this component owns rather than the page:
 *
 * - `sex`, `tags` and `exempt` need React state, because none of them is a
 *   plain text box. Each writes a hidden input, so the page's Inertia `<Form>`
 *   submits them with everything else and never has to know they exist.
 * - Empty strings, not absent keys: `ConvertEmptyStringsToNull` turns them
 *   into the nulls every `nullable` rule wants, which is also why a cleared
 *   date is submitted as '' rather than being dropped.
 * - The dates bind straight to `<input type="date">`. The resources send
 *   `YYYY-MM-DD` strings for exactly this reason — reparsing them as instants
 *   is what made every naive read name the day before.
 */
export function EmployeeFields({
    employee,
    errors,
}: {
    /** The record being edited, or nothing when one is being added. */
    employee?: Employee;
    errors: Partial<Record<string, string>>;
}) {
    const [sex, setSex] = useState<string>(employee?.sex ?? '');
    const [tags, setTags] = useState<string[]>(employee?.tags ?? []);
    const [exempt, setExempt] = useState<boolean>(employee?.exempt ?? false);

    return (
        <>
            {/*
              The narrow ones are narrow at the *control*, not at the field.
              MEASURED: with `w-[220px]` on the field, its hint inherited the
              220 and wrapped to two lines under a one-line box — §6.1 puts a
              hint below the field, and the field is 560 wide even when its
              control is not.
            */}
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
                                    <SelectItem value="female">Female</SelectItem>
                                    <SelectItem value="male">Male</SelectItem>
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

                {/*
                  A checkbox, not the switch the employees list filters with:
                  a switch turns something on now, and this records a fact
                  about a person that is saved with the rest of the form.
                  Geometry is §5.21's attest row — the box, the sentence, and
                  the hint that says who it is for.
                */}
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

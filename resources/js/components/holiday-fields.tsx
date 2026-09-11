import { useState } from 'react';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import { manilaToday } from '@/lib/dates';
import type { Choice, Holiday } from '@/types';

/**
 * The five columns of `holidays`, in one 560px column.
 *
 * `type` is a **rate**, not a scope, and the hints say so: who a holiday
 * applies to is which agency owns the row — the platform one meaning everyone
 * — while the type decides what a worked or unworked day is worth. The two
 * questions look alike and answering one with the other is how a local
 * ordinance ends up priced as a national regular holiday.
 *
 * `declared_at` is prospective (Res. 2600838 §2.5): workdays before it are not
 * recomputed. So it is the date on the paper, which may be well before today
 * and occasionally after the holiday itself, rather than the moment of data
 * entry.
 */
export function HolidayFields({
    holiday,
    rates,
    errors,
}: {
    holiday?: Holiday;
    rates: Choice[];
    errors: Partial<Record<string, string>>;
}) {
    const [type, setType] = useState<string>(holiday?.type.value ?? 'regular');

    return (
        <>
            <Field
                label="Name"
                htmlFor="name"
                error={errors.name}
                hint="Two holidays can share a date — both are owed — so the name is what tells them apart."
            >
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="name"
                        defaultValue={holiday?.name}
                        autoFocus
                        placeholder="Bonifacio Day"
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>

            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field label="Date" htmlFor="date" error={errors.date}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="date"
                            type="date"
                            defaultValue={holiday?.date ?? manilaToday()}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field label="Rate" htmlFor="type" error={errors.type} hint="What a worked or unworked day is worth.">
                    {({ id, invalid, describedBy }) => (
                        <Combobox
                            id={id}
                            name="type"
                            value={type}
                            onValueChange={(next) => setType(next ?? 'regular')}
                            invalid={invalid}
                            describedBy={describedBy}
                            placeholder="Choose a rate"
                            searchPlaceholder="Search"
                            empty="No such rate."
                            options={rates.map((option) => ({ ...option, trigger: option.label }))}
                        />
                    )}
                </Field>
            </div>

            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field
                    label="Reference"
                    htmlFor="reference"
                    error={errors.reference}
                    hint="Optional. The proclamation or ordinance number."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="reference"
                            defaultValue={holiday?.reference ?? ''}
                            placeholder="Proclamation No. 368"
                            maxLength={255}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field
                    label="Declared on"
                    htmlFor="declared_at"
                    error={errors.declared_at}
                    hint="The date on the paper. Days before it are not recomputed."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="declared_at"
                            type="date"
                            defaultValue={holiday?.declared_at?.slice(0, 10) ?? manilaToday()}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </div>
        </>
    );
}

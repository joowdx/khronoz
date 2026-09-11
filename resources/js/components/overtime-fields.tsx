import { useState } from 'react';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import type { Choice, Employee, Overtime } from '@/types';

/**
 * Authorising overtime.
 *
 * Both bounds are `datetime-local` rather than a date plus two clock times,
 * and that is the design rather than convenience: an authorisation routinely
 * crosses midnight, and 22:00–02:00 is one stretch of work. A date-and-times
 * form has to invent a rule for which day the end belongs to, and every such
 * rule is wrong for somebody; two timestamps simply say it.
 *
 * There is no field for the day it counts against — `overtimes.date` is
 * generated from `starts`, so an overnight stretch belongs to the day it began
 * on, and Postgres refuses an insert into that column outright.
 */
export function OvertimeFields({
    overtime,
    employees,
    modes,
    errors,
}: {
    overtime?: Overtime;
    employees: Employee[];
    modes: Choice[];
    errors: Partial<Record<string, string>>;
}) {
    const [employee, setEmployee] = useState<string | null>(overtime?.employee_id ?? null);
    const [mode, setMode] = useState<string>(overtime?.mode.value ?? 'pay');

    // `datetime-local` wants `YYYY-MM-DDTHH:MM`; the API sends a space and seconds.
    const local = (value?: string) => (value ? value.slice(0, 16).replace(' ', 'T') : undefined);

    return (
        <>
            <Field label="Person" htmlFor="employee_id" error={errors.employee_id}>
                {({ id, invalid, describedBy }) => (
                    <Combobox
                        id={id}
                        name="employee_id"
                        value={employee}
                        onValueChange={setEmployee}
                        invalid={invalid}
                        describedBy={describedBy}
                        placeholder="Choose an employee"
                        searchPlaceholder="Search employees"
                        empty="Nobody by that name."
                        options={employees.map((option) => ({
                            value: option.id,
                            label: option.name,
                            keywords: [option.number],
                            trigger: option.name,
                            render: (
                                <span className="flex min-w-0 items-baseline gap-2">
                                    <span className="truncate">{option.name}</span>
                                    <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                                        {option.number}
                                    </span>
                                </span>
                            ),
                        }))}
                    />
                )}
            </Field>

            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field
                    label="From"
                    htmlFor="starts"
                    error={errors.starts}
                    hint="Date and time — it may run past midnight."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="starts"
                            type="datetime-local"
                            defaultValue={local(overtime?.starts)}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field label="Until" htmlFor="ends" error={errors.ends}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="ends"
                            type="datetime-local"
                            defaultValue={local(overtime?.ends)}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </div>

            <Field
                className="mt-6"
                label="Purpose"
                htmlFor="purpose"
                error={errors.purpose}
                hint="What the work was for. It prints on the authorisation."
            >
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="purpose"
                        defaultValue={overtime?.purpose}
                        autoFocus
                        placeholder="Year-end closing of accounts"
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>

            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field
                    label="Compensated by"
                    htmlFor="mode"
                    error={errors.mode}
                    hint="Paid at the overtime rate, or earned as time off."
                >
                    {({ id, invalid, describedBy }) => (
                        <Combobox
                            id={id}
                            name="mode"
                            value={mode}
                            onValueChange={(next) => setMode(next ?? 'pay')}
                            invalid={invalid}
                            describedBy={describedBy}
                            placeholder="Choose how it is paid"
                            searchPlaceholder="Search"
                            empty="No such mode."
                            options={modes.map((option) => ({ ...option, trigger: option.label }))}
                        />
                    )}
                </Field>
                <Field
                    label="Reference"
                    htmlFor="reference"
                    error={errors.reference}
                    hint="Optional. The authority or order number."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="reference"
                            defaultValue={overtime?.reference ?? ''}
                            placeholder="Office Order No. 44"
                            maxLength={255}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </div>
        </>
    );
}

import { useState } from 'react';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { manilaToday } from '@/lib/dates';
import type { Choice, Employee, Exemption } from '@/types';

/**
 * Recording an exemption.
 *
 * The form's one real constraint is that **hours belong to a single day**
 * (`exemptions_span_is_whole_days`). A 10:00–14:00 window repeated across a
 * 105-day maternity leave is not what any order means, and a row saying it
 * would have the deriver excuse four hours a day of a continuous statutory
 * entitlement — under-excusing by the whole thing. So across a span the
 * switch is disabled and reads off, and the hours inputs are not offered.
 *
 * The switch is **submitted** rather than merely drawn. An unmounted input
 * sends nothing, an absent key is not a null, and `update()` then leaves the
 * hours exactly where they were — which is how extending a one-day exemption
 * used to reach `exemptions_span_is_whole_days` as a 23514, and how turning
 * the switch off used to report success and change nothing.
 *
 * `until` defaults to `date` because a one-day exemption is the common case
 * and, since decision 38, its canonical spelling.
 */
export function ExemptionFields({
    exemption,
    employees,
    types,
    errors,
}: {
    exemption?: Exemption;
    employees: Employee[];
    types: Choice[];
    errors: Partial<Record<string, string>>;
}) {
    const [employee, setEmployee] = useState<string | null>(exemption?.employee_id ?? null);
    const [type, setType] = useState<string>(exemption?.type.value ?? 'leave');
    const [date, setDate] = useState(exemption?.date ?? manilaToday());
    const [until, setUntil] = useState(exemption?.until ?? exemption?.date ?? manilaToday());
    const [partial, setPartial] = useState(Boolean(exemption?.starts));
    // Lifted out of the inputs on purpose. They unmount whenever the span
    // grows, and an uncontrolled input remounts at its default — so typing
    // 13:00–15:30, nudging the date, and coming back used to silently restore
    // 10:00–12:00.
    const [starts, setStarts] = useState(exemption?.starts?.slice(0, 5) ?? '10:00');
    const [ends, setEnds] = useState(exemption?.ends?.slice(0, 5) ?? '12:00');

    const oneDay = date === until;

    // The switch's *effect*, not its position. A span of more than one day
    // cannot carry hours, so the window is off there however the switch was
    // last left — and `partial` survives untouched, so shrinking the span back
    // to a single day restores the operator's own choice rather than a default.
    const windowed = partial && oneDay;

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

            <Field
                className="mt-6"
                label="Kind"
                htmlFor="type"
                error={errors.type}
                hint="Personal is recorded and excuses nothing."
            >
                {({ id, invalid, describedBy }) => (
                    <Combobox
                        id={id}
                        name="type"
                        value={type}
                        onValueChange={(next) => setType(next ?? 'leave')}
                        invalid={invalid}
                        describedBy={describedBy}
                        placeholder="Leave"
                        searchPlaceholder="Search"
                        empty="No such kind."
                        options={types.map((option) => ({ ...option, trigger: option.label }))}
                    />
                )}
            </Field>

            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field label="From" htmlFor="date" error={errors.date}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="date"
                            type="date"
                            value={date}
                            onChange={(event) => {
                                setDate(event.target.value);

                                if (until < event.target.value) {
                                    setUntil(event.target.value);
                                }
                            }}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field
                    label="Until"
                    htmlFor="until"
                    error={errors.until}
                    hint="The last day, included. Same as From for a single day."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="until"
                            type="date"
                            value={until}
                            min={date}
                            onChange={(event) => setUntil(event.target.value)}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </div>

            {/*
              Hours only apply to a single day (`exemptions_span_is_whole_days`).
              The switch **stays on the screen** across a span, disabled and
              off, saying why — it used to vanish, and a control that
              disappears leaves the operator no way to see that the hours their
              row still carried were about to be refused by a CHECK.

              `partial` is submitted on every save, so "off" is a value the
              server can act on rather than an absence it cannot distinguish
              from "unchanged".
            */}
            <input type="hidden" name="partial" value={windowed ? '1' : '0'} />

            <Field
                className="mt-6"
                label="Part of the day only"
                htmlFor="partial"
                hint={
                    oneDay
                        ? 'Off means the whole day is excused.'
                        : 'A leave spanning days excuses all of them. Set Until to the same day to excuse hours instead.'
                }
            >
                {({ id }) => (
                    <Switch id={id} checked={windowed} disabled={!oneDay} onCheckedChange={setPartial} />
                )}
            </Field>

            {windowed && (
                <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field label="From" htmlFor="starts" error={errors.starts}>
                        {({ id, invalid, describedBy }) => (
                            <Input
                                id={id}
                                name="starts"
                                type="time"
                                value={starts}
                                onChange={(event) => setStarts(event.target.value)}
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
                                type="time"
                                value={ends}
                                onChange={(event) => setEnds(event.target.value)}
                                aria-invalid={invalid}
                                aria-describedby={describedBy}
                            />
                        )}
                    </Field>
                </div>
            )}

            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field
                    label="Reference"
                    htmlFor="reference"
                    error={errors.reference}
                    hint="Optional. The application or order number."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="reference"
                            defaultValue={exemption?.reference ?? ''}
                            placeholder="Application No. 214"
                            maxLength={255}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field label="Approved on" htmlFor="approved_at" error={errors.approved_at}>
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="approved_at"
                            type="date"
                            defaultValue={exemption?.approved_at?.slice(0, 10) ?? manilaToday()}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </div>

            <Field className="mt-6" label="Remarks" htmlFor="remarks" error={errors.remarks}>
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="remarks"
                        defaultValue={exemption?.remarks ?? ''}
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>
        </>
    );
}

import { useState } from 'react';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { manilaToday } from '@/lib/dates';
import type { Choice, Employee, Exemption } from '@/types';

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
    const [starts, setStarts] = useState(exemption?.starts?.slice(0, 5) ?? '10:00');
    const [ends, setEnds] = useState(exemption?.ends?.slice(0, 5) ?? '12:00');

    const oneDay = date === until;

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

            {/* Submit an explicit off value when a span cannot carry hours. */}
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

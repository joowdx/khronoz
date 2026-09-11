import { useState } from 'react';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { flattenWorkgroups } from '@/lib/workgroups';
import { manilaToday } from '@/lib/dates';
import type { Suspension, Workgroup } from '@/types';

/**
 * Declaring a suspension.
 *
 * Two fields carry the weight and both are easy to get wrong silently.
 *
 * **Who it covers.** Leaving the workgroup empty suspends the whole agency,
 * which is the commonest case; naming one covers that workgroup *and
 * everything under it* (01-organization.md rule 4). The picker cannot show a
 * subtree, so the hint says it.
 *
 * **How much of the day.** Whole-day is the default and the switch reveals the
 * window rather than the other way round, because `suspensions_hours_paired`
 * refuses a half-set pair and a form that starts with two empty time boxes
 * invites exactly that. "Suspended from noon until nothing" is not a
 * declaration anyone can act on.
 */
export function SuspensionFields({
    suspension,
    workgroups,
    errors,
}: {
    suspension?: Suspension;
    workgroups: Workgroup[];
    errors: Partial<Record<string, string>>;
}) {
    const [workgroup, setWorkgroup] = useState<string | null>(suspension?.workgroup_id ?? null);
    const [partial, setPartial] = useState(Boolean(suspension?.starts));
    // Lifted out of the inputs: they unmount with the switch, and an
    // uncontrolled input remounts at its default rather than at what was typed.
    const [starts, setStarts] = useState(suspension?.starts?.slice(0, 5) ?? '12:00');
    const [ends, setEnds] = useState(suspension?.ends?.slice(0, 5) ?? '17:00');

    return (
        <>
            <Field label="Reason" htmlFor="reason" error={errors.reason} hint="It prints on the daily time record.">
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="reason"
                        defaultValue={suspension?.reason}
                        autoFocus
                        placeholder="Typhoon Signal No. 3"
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>

            <Field
                className="mt-6"
                label="Who it covers"
                htmlFor="workgroup_id"
                error={errors.workgroup_id}
                hint="Leave it blank for the whole agency. Naming one covers it and everything under it."
            >
                {({ id, invalid, describedBy }) => (
                    <Combobox
                        id={id}
                        name="workgroup_id"
                        value={workgroup}
                        onValueChange={setWorkgroup}
                        invalid={invalid}
                        describedBy={describedBy}
                        placeholder="The whole agency"
                        searchPlaceholder="Search workgroups"
                        empty="No workgroup by that name."
                        clearLabel="The whole agency"
                        options={flattenWorkgroups(workgroups).map(({ workgroup: option, depth }) => ({
                            value: option.id,
                            label: option.name,
                            keywords: [option.code, option.kind ?? ''],
                            trigger: option.name,
                            render: (
                                <span className="flex min-w-0 items-center" style={{ paddingLeft: depth * 14 }}>
                                    <span className="truncate">{option.name}</span>
                                    <span className="text-muted-foreground ml-2 shrink-0 text-xs">{option.code}</span>
                                </span>
                            ),
                        }))}
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
                            defaultValue={suspension?.date ?? manilaToday()}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field
                    label="Declared on"
                    htmlFor="declared_at"
                    error={errors.declared_at}
                    hint="The date on the memorandum."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="declared_at"
                            type="date"
                            defaultValue={suspension?.declared_at?.slice(0, 10) ?? manilaToday()}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </div>

            {/*
              `partial` is **submitted**, not merely drawn. An unmounted input
              sends nothing, and an absent key is not a null: `validated()`
              omits it, `update()` never names the column, and the hours
              already in the row survive a save the screen called successful.
              Turning the switch off did nothing at all.
            */}
            <input type="hidden" name="partial" value={partial ? '1' : '0'} />

            <Field
                className="mt-6"
                label="Part of the day only"
                htmlFor="partial"
                hint="Off means the whole day is suspended."
            >
                {({ id }) => <Switch id={id} checked={partial} onCheckedChange={setPartial} />}
            </Field>

            {partial && (
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

            <Field
                className="mt-6"
                label="Reference"
                htmlFor="reference"
                error={errors.reference}
                hint="Optional. The memorandum or announcement number."
            >
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="reference"
                        defaultValue={suspension?.reference ?? ''}
                        placeholder="Memorandum No. 12"
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>
        </>
    );
}

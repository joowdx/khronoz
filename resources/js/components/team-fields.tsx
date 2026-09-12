import { useState } from 'react';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { TurnStrip } from '@/components/schedule-fields';
import { Input } from '@/components/ui/input';
import { manilaToday } from '@/lib/dates';
import type { Schedule, Team } from '@/types';

export function TeamFields({
    team,
    schedules,
    errors,
}: {
    team?: Team;
    schedules: Schedule[];
    errors: Partial<Record<string, string>>;
}) {
    const [schedule, setSchedule] = useState<string | null>(team?.schedule_id ?? null);

    const chosen = schedules.find((option) => option.id === schedule) ?? null;

    return (
        <>
            <Field label="Name" htmlFor="name" error={errors.name} hint="What the roster grid will call this cohort.">
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="name"
                        defaultValue={team?.name}
                        autoFocus
                        placeholder="Team A"
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>

            <Field
                className="mt-6"
                label="Schedule"
                htmlFor="schedule_id"
                error={errors.schedule_id}
                hint="The cycle every member of this team follows."
            >
                {({ id, invalid, describedBy }) => (
                    <Combobox
                        id={id}
                        name="schedule_id"
                        value={schedule}
                        onValueChange={setSchedule}
                        invalid={invalid}
                        describedBy={describedBy}
                        placeholder="Choose a schedule"
                        searchPlaceholder="Search schedules"
                        empty="No such schedule."
                        options={schedules.map((option) => ({
                            value: option.id,
                            label: option.name,
                            keywords: [`${option.length} days`],
                            trigger: option.name,
                            render: (
                                <span className="flex min-w-0 items-baseline gap-2">
                                    <span className="truncate">{option.name}</span>
                                    <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                                        {cycle(option.length)}
                                    </span>
                                </span>
                            ),
                        }))}
                    />
                )}
            </Field>

            {chosen && (chosen.turns ?? []).length > 0 && (
                <div className="border-border mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border p-4">
                    <TurnStrip turns={chosen.turns ?? []} />
                    <span className="text-muted-foreground text-xs leading-4 tabular-nums">
                        {cycle(chosen.length)}, from day 1 on the anchor
                    </span>
                </div>
            )}

            <Field
                className="mt-6 sm:w-[240px]"
                label="Anchor"
                htmlFor="anchor"
                error={errors.anchor}
                hint="Day 1 of the cycle for this whole team. Two teams on one schedule are told apart by this date alone."
            >
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="anchor"
                        type="date"
                        defaultValue={team?.anchor ?? manilaToday()}
                        className="tabular-nums"
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>
        </>
    );
}

function cycle(length: number): string {
    return `${length} ${length === 1 ? 'day' : 'days'}`;
}

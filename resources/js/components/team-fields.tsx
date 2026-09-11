import { useState } from 'react';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { TurnStrip } from '@/components/schedule-fields';
import { Input } from '@/components/ui/input';
import { manilaToday } from '@/lib/dates';
import type { Schedule, Team } from '@/types';

/**
 * A team is three facts: a name, a schedule, and the date its cycle starts on.
 *
 * **There is no membership field, and adding one would be wrong.** A team has
 * no members of its own — the rosters carrying its `team_id` *are* its
 * membership (04-scheduling.md), so people join a team by being rostered into
 * it on the roster grid, and "who was on it in March" is answered from those
 * rosters' own date ranges. There is no date range here either: a team is a
 * standing definition, not an arrangement that starts and ends.
 *
 * What the anchor buys is the whole point of the model. Three teams on one
 * 21-day rotation, anchored seven days apart, sit seven positions apart on
 * every date — which is how a hospital covers morning, afternoon and night
 * with one schedule and three rows. So the chosen schedule's cycle is drawn
 * under the picker: the anchor decides where in *that* strip the team begins,
 * and choosing it blind is what makes three teams accidentally land on the
 * same shift.
 */
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
                        // A `YYYY-MM-DD` string straight from the API, never
                        // through `new Date()` — pages.md: a bare date parsed
                        // as a UTC instant names the day before in Manila.
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

/** "21 days", and "1 day" for the schedule that is every day the same. */
function cycle(length: number): string {
    return `${length} ${length === 1 ? 'day' : 'days'}`;
}

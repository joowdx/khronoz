import { Link } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { OffBox, RemoteBox, ShiftChip, type Slot } from '@/components/shift-chip';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { create as addShift } from '@/routes/shifts';
import type { Schedule, Shift, Turn } from '@/types';

const DEFAULT_LENGTH = 7;
const MAX_LENGTH = 366;

const STRIP_LIMIT = 14;

export function ShiftGlyph({ shift, className }: { shift: Shift; className?: string }) {
    const size = cn('size-[18px]', className);

    if (shift.kind === 'off') {
        return <OffBox className={size} />;
    }

    return (
        <span aria-hidden className="flex">
            {shift.kind === 'remote' ? (
                <RemoteBox className={size} />
            ) : (
                <ShiftChip slot={slot(shift.color)} className={size}>
                    {shift.name.slice(0, 1).toUpperCase()}
                </ShiftChip>
            )}
        </span>
    );
}

export function TurnStrip({ turns, className }: { turns: Turn[]; className?: string }) {
    const shown = turns.slice(0, STRIP_LIMIT);
    const rest = turns.length - shown.length;

    return (
        <span className={cn('flex min-w-0 items-center gap-1 overflow-hidden', className)}>
            {shown.map((turn) => (turn.shift ? <ShiftGlyph key={turn.id} shift={turn.shift} /> : null))}
            {rest > 0 && <span className="text-muted-foreground shrink-0 text-xs tabular-nums">+{rest}</span>}
        </span>
    );
}

export function ScheduleFields({
    schedule,
    shifts,
    errors,
}: {
    schedule?: Schedule;
    shifts: Shift[];
    errors: Partial<Record<string, string>>;
}) {
    const [lengthText, setLengthText] = useState(String(schedule?.length ?? DEFAULT_LENGTH));
    const [turns, setTurns] = useState<string[]>(() => opening(schedule, shifts));
    const [fallback, setFallback] = useState<string | null>(schedule?.fallback_shift_id ?? null);

    const labelId = useId();
    const errorId = useId();

    const fault = errors.turns ?? Object.entries(errors).find(([key]) => key.startsWith('turns.'))?.[1];

    const options = shifts.map((shift) => ({
        value: shift.id,
        label: shift.name,
        render: <ShiftOption shift={shift} />,
        trigger: <ShiftOption shift={shift} />,
    }));

    function relength(raw: string): void {
        setLengthText(raw);

        const next = Number(raw);

        if (Number.isInteger(next) && next >= 1 && next <= MAX_LENGTH) {
            setTurns((current) => resized(current, next, shifts));
        }
    }

    function choose(position: number, shift: string | null): void {
        setTurns((current) => current.map((held, index) => (index === position ? (shift ?? '') : held)));
    }

    return (
        <>
            <Field
                label="Name"
                htmlFor="name"
                error={errors.name}
                hint="What the teams and the roster grid will call it."
            >
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="name"
                        defaultValue={schedule?.name}
                        autoFocus
                        placeholder="Standard week"
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>

            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field
                    label="Cycle length"
                    htmlFor="length"
                    error={errors.length}
                    hint="Days before it repeats. 7 is a week; 21 is a three-week rotation."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="length"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={MAX_LENGTH}
                            className="tabular-nums"
                            value={lengthText}
                            onChange={(event) => relength(event.target.value)}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field
                    label="Falls back to"
                    htmlFor="fallback_shift_id"
                    error={errors.fallback_shift_id}
                    hint="Optional. What the rest of an ISO week works when a holiday lands on one of its rest days."
                >
                    {({ id, invalid, describedBy }) => (
                        <Combobox
                            id={id}
                            name="fallback_shift_id"
                            value={fallback}
                            onValueChange={setFallback}
                            invalid={invalid}
                            describedBy={describedBy}
                            placeholder="No revert"
                            clearLabel="No revert"
                            searchPlaceholder="Search shifts"
                            empty="No such shift."
                            options={options}
                        />
                    )}
                </Field>
            </div>

            <div className="mt-8">
                <div className="flex items-baseline justify-between gap-3 pb-1.5">
                    <span id={labelId} className="text-[13px] leading-[18px] font-medium">
                        The cycle
                    </span>
                    <p
                        id={errorId}
                        aria-live="polite"
                        className="text-destructive min-h-[18px] text-right text-[13px] leading-[18px] font-medium"
                    >
                        {fault || '\u200b'}
                    </p>
                </div>

                {shifts.length === 0 ? (
                    <div className="border-border rounded-xl border p-5">
                        <p className="text-muted-foreground text-sm leading-5">
                            A cycle is built from shifts, and this agency has none yet.{' '}
                            <Link
                                href={addShift()}
                                className="text-acc-text font-medium underline-offset-4 hover:underline"
                            >
                                Add a shift
                            </Link>{' '}
                            first, or copy the defaults.
                        </p>
                    </div>
                ) : (
                    <div
                        role="group"
                        aria-labelledby={labelId}
                        aria-describedby={fault ? errorId : undefined}
                        className="border-border grid grid-cols-[repeat(auto-fill,minmax(148px,1fr))] gap-3 rounded-xl border p-5"
                    >
                        {turns.map((shift, position) => (
                            <div key={position} className="min-w-0">
                                <span
                                    aria-hidden
                                    className="text-muted-foreground block pb-1 text-[11px] leading-[14px] font-medium tabular-nums"
                                >
                                    Day {position + 1}
                                </span>
                                <Combobox
                                    name={`turns[${position}]`}
                                    label={`Day ${position + 1}`}
                                    value={shift === '' ? null : shift}
                                    onValueChange={(next) => choose(position, next)}
                                    invalid={errors[`turns.${position}`] ? true : undefined}
                                    placeholder="Choose"
                                    searchPlaceholder="Search shifts"
                                    empty="No such shift."
                                    options={options}
                                />
                            </div>
                        ))}
                    </div>
                )}

                <p className="text-muted-foreground pt-1.5 text-xs leading-4">
                    One shift for each day, in order. Changing the length above grows or shrinks this, repeating what is
                    already here.
                </p>
            </div>
        </>
    );
}

function ShiftOption({ shift }: { shift: Shift }) {
    return (
        <span className="flex min-w-0 items-center gap-2">
            <ShiftGlyph shift={shift} />
            <span className="truncate">{shift.name}</span>
        </span>
    );
}

function slot(color: number): Slot {
    return (((((Math.trunc(color) - 1) % 8) + 8) % 8) + 1) as Slot;
}

function opening(schedule: Schedule | undefined, shifts: Shift[]): string[] {
    const chosen = [...(schedule?.turns ?? [])].sort((a, b) => a.position - b.position).map((turn) => turn.shift_id);

    return resized(chosen, schedule?.length ?? DEFAULT_LENGTH, shifts);
}

function resized(current: string[], length: number, shifts: Shift[]): string[] {
    if (length <= current.length) {
        return current.slice(0, length);
    }

    const pattern = current.length > 0 ? current : [shifts[0]?.id ?? ''];

    return Array.from({ length }, (_, position) => current[position] ?? pattern[position % pattern.length] ?? '');
}

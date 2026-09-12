import { useState } from 'react';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import { OffBox, RAMP, RemoteBox, SLOTS, ShiftChip } from '@/components/shift-chip';
import { Field } from '@/components/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { Shift, ShiftSlot } from '@/types';
import type { Slot } from '@/components/shift-chip';

/**
 * Three kinds of shift (04-scheduling.md):
 *   - `working` — has in/out slot pairs; earns credit from punches.
 *   - `off`     — no slots, no credit; a rest day.
 *   - `remote`  — no slots; credited on attestation (Flexiplace, MC 114).
 *
 * `kind` is a derived label the editor uses for its conditional sections. On
 * submit, the actual database columns (`slots`, `remote`) carry the intent:
 * a working shift sends its slots array; off and remote both send `slots: []`,
 * with `remote` false or true respectively.
 *
 * The colour picker is hidden when kind is `off` or `remote` — neither takes
 * a ramp index (shift-chip.tsx). `slots`, `required` and `flex` are hidden
 * when kind is not `working`.
 *
 * **Naming trap** (shift-chip.tsx): `Slot` exported from `shift-chip.tsx` is
 * the colour ramp index 1–8. The domain's in/out pair is `ShiftSlot` from
 * `resources/js/types/index.d.ts`. They are two different things.
 */
export function ShiftFields({
    shift,
    usedColors,
    errors,
}: {
    /** Absent on create. Present on edit. */
    shift?: Shift;
    /**
     * Ramp indices (1–8) the agency already uses, so the picker can mark
     * them as taken. Comes from the controller as a prop.
     */
    usedColors: number[];
    errors: Partial<Record<string, string>>;
}) {
    const initialKind = shift
        ? shift.kind
        : 'working';

    const [kind, setKind] = useState<'working' | 'off' | 'remote'>(initialKind);

    const [color, setColor] = useState<number>(shift?.color ?? 1);

    const [slots, setSlots] = useState<ShiftSlot[]>(
        shift?.slots && shift.slots.length > 0
            ? shift.slots
            : kind === 'working'
              ? [{ in: '08:00', out: '17:00', grace: 0, window: [-240, 300] }]
              : [],
    );

    function addSlot() {
        setSlots((prev) => [...prev, { in: '08:00', out: '17:00', grace: 0, window: [-240, 300] }]);
    }

    function removeSlot(index: number) {
        setSlots((prev) => prev.filter((_, i) => i !== index));
    }

    function updateSlot(index: number, field: keyof ShiftSlot, value: string | number) {
        setSlots((prev) =>
            prev.map((slot, i) => {
                if (i !== index) {
                    return slot;
                }

                return { ...slot, [field]: value };
            }),
        );
    }

    function updateSlotWindow(index: number, side: 0 | 1, value: number) {
        setSlots((prev) =>
            prev.map((slot, i) => {
                if (i !== index) {
                    return slot;
                }

                const win: [number, number] = [slot.window[0], slot.window[1]];
                win[side] = value;

                return { ...slot, window: win };
            }),
        );
    }

    // When kind changes, reset slots and remote to sensible defaults.
    function handleKindChange(next: 'working' | 'off' | 'remote') {
        setKind(next);
        if (next !== 'working') {
            setSlots([]);
        } else if (slots.length === 0) {
            setSlots([{ in: '08:00', out: '17:00', grace: 0, window: [-240, 300] }]);
        }
    }

    const isWorking = kind === 'working';

    return (
        <>
            <input type="hidden" name="remote" value={kind === 'remote' ? '1' : '0'} />

            {isWorking
                ? slots.map((slot, i) => (
                      <input
                          key={i}
                          type="hidden"
                          name={`slots[${i}]`}
                          value={JSON.stringify({
                              in: slot.in,
                              out: slot.out,
                              grace: slot.grace ?? 0,
                              window: slot.window,
                          })}
                      />
                  ))
                : null}

            <Field label="Name" htmlFor="name" error={errors.name}>
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="name"
                        defaultValue={shift?.name}
                        autoFocus
                        placeholder="Standard 8-5"
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>

            <Field className="mt-6" label="Kind" htmlFor="kind" error={errors.kind}>
                {({ id }) => (
                    <Select value={kind} onValueChange={(v) => handleKindChange(v as typeof kind)}>
                        <SelectTrigger id={id}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="working">Working — has in/out slot pairs</SelectItem>
                            <SelectItem value="off">Off — rest day, no credit</SelectItem>
                            <SelectItem value="remote">Remote — no punches, credited on attestation</SelectItem>
                        </SelectContent>
                    </Select>
                )}
            </Field>

            {isWorking && (
                <>
                    <div className="mt-6">
                        <div className="mb-2 flex items-center justify-between">
                            <Label>Time slots</Label>
                            <Button type="button" variant="ghost" size="sm" onClick={addSlot}>
                                <PlusIcon aria-hidden strokeWidth={1.5} />
                                Add slot
                            </Button>
                        </div>

                        {errors.slots && (
                            <p className="text-destructive mb-2 text-sm">{errors.slots}</p>
                        )}

                        {slots.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No slots yet. A working shift needs at least one.
                            </p>
                        ) : (
                            <div className="space-y-4">
                                {slots.map((slot, i) => (
                                    <div
                                        key={i}
                                        className="bg-muted/30 rounded-lg border p-4"
                                    >
                                        <div className="mb-3 flex items-center justify-between">
                                            <span className="text-muted-foreground text-sm font-medium tabular-nums">
                                                Slot {i + 1}
                                            </span>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={`Remove slot ${i + 1}`}
                                                onClick={() => removeSlot(i)}
                                            >
                                                <Trash2Icon aria-hidden strokeWidth={1.5} />
                                            </Button>
                                        </div>

                                        <div className="grid grid-cols-2 gap-3">
                                            <Field
                                                label="In"
                                                htmlFor={`slots-${i}-in`}
                                                error={errors[`slots.${i}.in`]}
                                                hint="30:00 = 06:00 next day"
                                            >
                                                {({ id, invalid, describedBy }) => (
                                                    <Input
                                                        id={id}
                                                        value={slot.in}
                                                        onChange={(e) => updateSlot(i, 'in', e.target.value)}
                                                        placeholder="08:00"
                                                        aria-invalid={invalid}
                                                        aria-describedby={describedBy}
                                                    />
                                                )}
                                            </Field>
                                            <Field
                                                label="Out"
                                                htmlFor={`slots-${i}-out`}
                                                error={errors[`slots.${i}.out`]}
                                                hint="30:00 = 06:00 next day"
                                            >
                                                {({ id, invalid, describedBy }) => (
                                                    <Input
                                                        id={id}
                                                        value={slot.out}
                                                        onChange={(e) => updateSlot(i, 'out', e.target.value)}
                                                        placeholder="12:00"
                                                        aria-invalid={invalid}
                                                        aria-describedby={describedBy}
                                                    />
                                                )}
                                            </Field>
                                        </div>

                                        <div className="mt-3 grid grid-cols-3 gap-3">
                                            <Field
                                                label="Grace (min)"
                                                htmlFor={`slots-${i}-grace`}
                                                error={errors[`slots.${i}.grace`]}
                                                hint="Still on time"
                                            >
                                                {({ id, invalid, describedBy }) => (
                                                    <Input
                                                        id={id}
                                                        type="number"
                                                        min={0}
                                                        className="tabular-nums"
                                                        value={slot.grace ?? 0}
                                                        onChange={(e) =>
                                                            updateSlot(i, 'grace', parseInt(e.target.value, 10) || 0)
                                                        }
                                                        aria-invalid={invalid}
                                                        aria-describedby={describedBy}
                                                    />
                                                )}
                                            </Field>
                                            <Field
                                                label="Window before (min)"
                                                htmlFor={`slots-${i}-window-0`}
                                                error={errors[`slots.${i}.window.0`]}
                                                hint="≤ 0, before In"
                                            >
                                                {({ id, invalid, describedBy }) => (
                                                    <Input
                                                        id={id}
                                                        type="number"
                                                        max={0}
                                                        className="tabular-nums"
                                                        value={slot.window[0]}
                                                        onChange={(e) =>
                                                            updateSlotWindow(i, 0, parseInt(e.target.value, 10) || 0)
                                                        }
                                                        aria-invalid={invalid}
                                                        aria-describedby={describedBy}
                                                    />
                                                )}
                                            </Field>
                                            <Field
                                                label="Window after (min)"
                                                htmlFor={`slots-${i}-window-1`}
                                                error={errors[`slots.${i}.window.1`]}
                                                hint="≥ 0, after Out"
                                            >
                                                {({ id, invalid, describedBy }) => (
                                                    <Input
                                                        id={id}
                                                        type="number"
                                                        min={0}
                                                        className="tabular-nums"
                                                        value={slot.window[1]}
                                                        onChange={(e) =>
                                                            updateSlotWindow(i, 1, parseInt(e.target.value, 10) || 0)
                                                        }
                                                        aria-invalid={invalid}
                                                        aria-describedby={describedBy}
                                                    />
                                                )}
                                            </Field>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="mt-6 grid grid-cols-2 gap-3">
                        <Field
                            label="Required (min)"
                            htmlFor="required"
                            error={errors.required}
                            hint="Minutes a full day credits."
                        >
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="required"
                                    type="number"
                                    min={0}
                                    className="tabular-nums"
                                    defaultValue={shift?.required ?? 480}
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                />
                            )}
                        </Field>
                        <Field
                            label="Flex (min)"
                            htmlFor="flex"
                            error={errors.flex}
                            hint="0 for a fixed shift."
                        >
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="flex"
                                    type="number"
                                    min={0}
                                    className="tabular-nums"
                                    defaultValue={shift?.flex ?? 0}
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                />
                            )}
                        </Field>
                    </div>
                </>
            )}

            {/* required and flex for off/remote — hidden but still submitted so validation passes */}
            {!isWorking && (
                <>
                    <input type="hidden" name="required" value={shift?.required ?? 0} />
                    <input type="hidden" name="flex" value="0" />
                </>
            )}

            <Field
                className="mt-6"
                label="Trust device state"
                htmlFor="trust"
                error={errors.trust}
                hint="When on, the device's own in/out state is taken as truth when matching timelogs."
            >
                {({ id }) => (
                    <Select
                        name="trust"
                        defaultValue={shift?.trust ? '1' : '0'}
                        onValueChange={() => {}}
                    >
                        <SelectTrigger id={id}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="0">No — use as a hint only</SelectItem>
                            <SelectItem value="1">Yes — device state is authoritative</SelectItem>
                        </SelectContent>
                    </Select>
                )}
            </Field>

            {/* Colour picker — hidden when kind is off or remote (neither takes a ramp index). */}
            {isWorking && (
                <div className="mt-6">
                    <input type="hidden" name="color" value={color} />
                    <Label>Colour</Label>
                    <p className="text-muted-foreground mb-3 text-sm">
                        Choose the roster colour. Marked slots are already used by another shift.
                    </p>
                    {errors.color && (
                        <p className="text-destructive mb-2 text-sm">{errors.color}</p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {SLOTS.map((slot: Slot) => {
                            const isSelected = color === slot;
                            const isUsed = usedColors.includes(slot) && !isSelected;

                            return (
                                <button
                                    key={slot}
                                    type="button"
                                    aria-label={`Colour ${slot}${isUsed ? ' — already used' : ''}`}
                                    aria-pressed={isSelected}
                                    onClick={() => setColor(slot)}
                                    className={cn(
                                        'relative size-8 rounded-md transition-[color,background-color,border-color] focus-visible:outline-2 focus-visible:outline-offset-2',
                                        isSelected && 'ring-2 ring-offset-2',
                                        RAMP[slot],
                                    )}
                                >
                                    <span className="tabular-nums text-[11px] font-semibold leading-none">
                                        {slot}
                                    </span>
                                    {isUsed && (
                                        <span
                                            aria-hidden
                                            className="absolute -right-0.5 -top-0.5 size-2 rounded-full bg-white ring-1 ring-current"
                                        />
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {/* color: off and remote shifts still need a value to pass validation */}
            {!isWorking && <input type="hidden" name="color" value={shift?.color ?? 1} />}
        </>
    );
}

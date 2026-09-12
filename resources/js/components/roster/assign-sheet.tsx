import { Form } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { CyclePreview, type CycleTurn } from '@/components/roster/cycle-preview';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { store } from '@/routes/rosters';
import type { Slot } from '@/components/shift-chip';
import type { Schedule } from '@/types';

export function AssignSheet({
    open,
    onOpenChange,
    employees,
    schedules,
    month,
    onAssigned,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employees: string[];
    schedules: Schedule[];
    month: string;
    agency: string | null;
    onAssigned: () => void;
}) {
    const [schedule, setSchedule] = useState<string | null>(null);
    const chosen = schedules.find((option) => option.id === schedule) ?? null;

    const turns: CycleTurn[] = useMemo(() => {
        if (chosen?.turns === undefined) {
            return [];
        }

        return [...chosen.turns]
            .sort((a, b) => a.position - b.position)
            .map((turn) => ({
                position: turn.position,
                slot: turn.shift === undefined || turn.shift.kind !== 'working' ? null : (turn.shift.color as Slot),
                name: turn.shift?.name ?? 'Unassigned',
            }));
    }, [chosen]);

    const first = `${month}-01`;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="w-[420px] sm:max-w-[420px]">
                <SheetHeader>
                    <SheetTitle>Assign schedule</SheetTitle>
                </SheetHeader>

                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        onAssigned();
                        onOpenChange(false);
                    }}
                    disableWhileProcessing
                    className="flex min-h-0 flex-1 flex-col"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="min-h-0 flex-1 overflow-auto px-4">
                                <p className="text-muted-foreground pb-5 text-[13px] leading-[19px]">
                                    {employees.length} {employees.length === 1 ? 'employee' : 'employees'} selected.
                                    Assigning a schedule ends their current roster on the day before this one starts.
                                </p>

                                {employees.map((id) => (
                                    <input key={id} type="hidden" name="employees[]" value={id} />
                                ))}

                                <Field label="Schedule" error={errors.schedule_id}>
                                    {({ id, invalid, describedBy }) => (
                                        <>
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
                                                }))}
                                            />
                                            <CyclePreview turns={turns} />
                                            {chosen !== null && (
                                                <p className="text-muted-foreground pt-2 text-xs leading-4">
                                                    {chosen.length}-day cycle.
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Field>

                                <div className="mt-4">
                                    <Field label="Anchor date" hint="Cycle day 1" error={errors.anchor}>
                                        {({ id, invalid, describedBy }) => (
                                            <Input
                                                id={id}
                                                name="anchor"
                                                type="date"
                                                defaultValue={first}
                                                className="tabular-nums"
                                                aria-invalid={invalid}
                                                aria-describedby={describedBy}
                                            />
                                        )}
                                    </Field>
                                </div>

                                <div className="mt-4">
                                    <Field label="Starts" error={errors.starts}>
                                        {({ id, invalid, describedBy }) => (
                                            <Input
                                                id={id}
                                                name="starts"
                                                type="date"
                                                defaultValue={first}
                                                className="tabular-nums"
                                                aria-invalid={invalid}
                                                aria-describedby={describedBy}
                                            />
                                        )}
                                    </Field>
                                </div>

                                <div className="mt-4">
                                    <Field label="Ends" hint="Optional" error={errors.ends}>
                                        {({ id, invalid, describedBy }) => (
                                            <Input
                                                id={id}
                                                name="ends"
                                                type="date"
                                                placeholder="Leave empty to keep running"
                                                className="tabular-nums"
                                                aria-invalid={invalid}
                                                aria-describedby={describedBy}
                                            />
                                        )}
                                    </Field>
                                </div>
                            </div>

                            <SheetFooter>
                                <Button type="submit" disabled={processing || employees.length === 0}>
                                    Assign schedule
                                </Button>
                                <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                                    Cancel
                                </Button>
                            </SheetFooter>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}

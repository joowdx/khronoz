import { Field } from '@/components/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Choice } from '@/types';

export function ChoiceField({
    label,
    name,
    value,
    onChange,
    choices,
    error,
    hint,
    emptyLabel,
    disabled = false,
}: {
    label: string;
    name?: string;
    value: string;
    onChange: (value: string) => void;
    choices: Choice[];
    error?: string;
    hint?: string;
    emptyLabel?: string;
    disabled?: boolean;
}) {
    return (
        <Field label={label} error={error} hint={hint}>
            {({ id, invalid, describedBy }) => (
                <>
                    {name && <input type="hidden" name={name} value={value} />}
                    <Select
                        value={value || '__empty'}
                        onValueChange={(next) => onChange(next === '__empty' ? '' : next)}
                        disabled={disabled}
                    >
                        <SelectTrigger id={id} className="w-full" aria-invalid={invalid} aria-describedby={describedBy}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent position="popper" className="z-[60]">
                            {emptyLabel && <SelectItem value="__empty">{emptyLabel}</SelectItem>}
                            {choices.map((choice) => (
                                <SelectItem key={choice.value} value={choice.value}>
                                    {choice.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </>
            )}
        </Field>
    );
}

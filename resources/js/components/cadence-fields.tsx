import { useState } from 'react';
import { ChoiceField } from '@/components/choice-field';
import { Field } from '@/components/field';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { Cadence, Choice } from '@/types';

export function CadenceFields({
    cadence,
    kinds,
    errors,
}: {
    cadence?: Cadence;
    kinds: Choice[];
    errors: Record<string, string>;
}) {
    const [kind, setKind] = useState(cadence?.kind.value ?? 'monthly');
    const [preferred, setPreferred] = useState(cadence?.preferred ?? false);
    const anchored = kind === 'weekly' || kind === 'fortnightly';
    return (
        <div className="grid gap-6">
            <Field label="Name" error={errors.name}>
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="name"
                        defaultValue={cadence?.name}
                        required
                        maxLength={255}
                        autoFocus
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>
            <ChoiceField
                label="Frequency"
                name="kind"
                value={kind}
                onChange={setKind}
                choices={kinds}
                error={errors.kind}
            />
            {anchored ? (
                <Field
                    label="Anchor date"
                    error={errors.anchor}
                    hint="The first day of a known period. Future and past periods repeat from this date."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="anchor"
                            type="date"
                            defaultValue={cadence?.anchor ?? ''}
                            required
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        label="First starting day"
                        error={errors['rules.starts.0'] ?? errors['rules.starts']}
                        hint="Day of the month, from 1 to 28."
                    >
                        {({ id, invalid, describedBy }) => (
                            <Input
                                id={id}
                                name="rules[starts][0]"
                                type="number"
                                min={1}
                                max={28}
                                defaultValue={cadence?.rules.starts?.[0] ?? 1}
                                required
                                aria-invalid={invalid}
                                aria-describedby={describedBy}
                            />
                        )}
                    </Field>
                    {kind === 'semimonthly' && (
                        <Field
                            label="Second starting day"
                            error={errors['rules.starts.1']}
                            hint="Later than the first starting day."
                        >
                            {({ id, invalid, describedBy }) => (
                                <Input
                                    id={id}
                                    name="rules[starts][1]"
                                    type="number"
                                    min={2}
                                    max={28}
                                    defaultValue={cadence?.rules.starts?.[1] ?? 16}
                                    required
                                    aria-invalid={invalid}
                                    aria-describedby={describedBy}
                                />
                            )}
                        </Field>
                    )}
                </div>
            )}
            <Field
                label="Agency default"
                error={errors.preferred}
                hint="Used when an employee has no assigned cadence."
            >
                {({ id }) => (
                    <div className="flex items-center gap-3">
                        <input type="hidden" name="preferred" value={preferred ? '1' : '0'} />
                        <Checkbox
                            id={id}
                            checked={preferred}
                            onCheckedChange={(checked) => setPreferred(checked === true)}
                        />
                        <span className="text-sm">Use this cadence by default</span>
                    </div>
                )}
            </Field>
        </div>
    );
}

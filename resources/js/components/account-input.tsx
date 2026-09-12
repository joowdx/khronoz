import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import type { ComponentProps } from 'react';

export function AccountInput({
    label,
    error,
    hint,
    ...props
}: ComponentProps<typeof Input> & { label: string; error?: string; hint?: string }) {
    return (
        <Field label={label} htmlFor={props.id ?? props.name} error={error} hint={hint}>
            {({ id, invalid, describedBy }) => (
                <Input {...props} id={id} aria-invalid={invalid} aria-describedby={describedBy} />
            )}
        </Field>
    );
}

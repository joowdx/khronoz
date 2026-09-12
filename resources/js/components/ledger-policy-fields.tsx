import { useState } from 'react';
import { ArrowDown, ArrowUp, X } from 'lucide-react';
import { ChoiceField } from '@/components/choice-field';
import { Field } from '@/components/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { AttestationRole, Choice, LedgerPolicy } from '@/types';

export interface PolicyChoices {
    templates: Choice[];
    roles: Choice[];
    supervisors: Choice[];
}
export function LedgerPolicyFields({
    policy,
    choices,
    errors,
    prefix = '',
}: {
    policy?: LedgerPolicy | null;
    choices: PolicyChoices;
    errors: Record<string, string>;
    prefix?: string;
}) {
    const [template, setTemplate] = useState(policy?.template ?? '');
    const [supervisor, setSupervisor] = useState(policy?.supervisor ?? '');
    const [roles, setRoles] = useState<AttestationRole[] | null>(policy?.roles ?? null);
    const name = (key: string) => (prefix ? prefix + '[' + key + ']' : key);
    const errorKey = (key: string) => (prefix ? prefix + '.' + key : key);
    function move(index: number, direction: number) {
        if (!roles) return;
        const next = [...roles];
        [next[index], next[index + direction]] = [next[index + direction]!, next[index]!];
        setRoles(next);
    }
    return (
        <div className="grid gap-6">
            <ChoiceField
                label="Document template"
                name={name('template')}
                value={template}
                onChange={(value) => setTemplate(value as typeof template)}
                choices={choices.templates}
                emptyLabel="Inherit template"
                error={errors[errorKey('template')]}
            />
            <Field
                label="Attestation order"
                error={
                    errors[errorKey('roles')] ??
                    Object.entries(errors).find(([key]) => key.startsWith(errorKey('roles') + '.'))?.[1]
                }
                hint="Each person attests after the preceding role. Clear this list to inherit the applicable policy."
            >
                {({ id, describedBy }) => (
                    <div id={id} aria-describedby={describedBy} className="grid gap-3">
                        {roles === null ? (
                            <>
                                <input type="hidden" name={name('roles')} value="" />
                                <p className="text-muted-foreground text-sm">Use inherited attestation roles</p>
                            </>
                        ) : (
                            <ol className="grid gap-2">
                                {roles.map((role, index) => (
                                    <li key={role} className="flex items-center gap-2 rounded-lg border px-3 py-2">
                                        <input type="hidden" name={name('roles') + '[' + index + ']'} value={role} />
                                        <span className="mr-auto text-sm">
                                            {index + 1}.{' '}
                                            {choices.roles.find((choice) => choice.value === role)?.label ?? role}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            disabled={index === 0}
                                            aria-label={'Move ' + role + ' earlier'}
                                            onClick={() => move(index, -1)}
                                        >
                                            <ArrowUp />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            disabled={index === roles.length - 1}
                                            aria-label={'Move ' + role + ' later'}
                                            onClick={() => move(index, 1)}
                                        >
                                            <ArrowDown />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={'Remove ' + role}
                                            onClick={() => {
                                                const remaining = roles.filter((item) => item !== role);
                                                setRoles(remaining.length ? remaining : null);
                                            }}
                                        >
                                            <X />
                                        </Button>
                                    </li>
                                ))}
                            </ol>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {choices.roles
                                .filter((choice) => !roles?.includes(choice.value as AttestationRole))
                                .map((choice) => (
                                    <Button
                                        type="button"
                                        key={choice.value}
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setRoles([...(roles ?? []), choice.value as AttestationRole])}
                                    >
                                        Add {choice.label.toLowerCase()}
                                    </Button>
                                ))}
                            {roles && (
                                <Button type="button" variant="ghost" size="sm" onClick={() => setRoles(null)}>
                                    Use inherited order
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </Field>
            <ChoiceField
                label="Supervisor basis"
                name={name('supervisor')}
                value={supervisor}
                onChange={(value) => setSupervisor(value as typeof supervisor)}
                choices={choices.supervisors}
                emptyLabel="Inherit supervisor basis"
                error={errors[errorKey('supervisor')]}
            />
            <Field
                label="Head workgroup kind"
                error={errors[errorKey('head_kind')]}
                hint="For the head role, use the agency's workgroup kind, such as division or department. Leave blank to inherit."
            >
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name={name('head_kind')}
                        defaultValue={policy?.head_kind ?? ''}
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>
        </div>
    );
}

import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { ChoiceField } from '@/components/choice-field';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { LedgerPolicyFields, type PolicyChoices } from '@/components/ledger-policy-fields';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { update } from '@/routes/agency/settings';
import { update as updateEmployee } from '@/routes/employees/policy';
import { update as updateWorkgroup } from '@/routes/workgroups/policy';
import type { Choice, Employee, LedgerPolicy, Workgroup } from '@/types';

interface Settings {
    ledger_archiving: boolean;
    night_from: string;
    overtime_after_weekly: number | null;
    occurrences: boolean;
    suspension_charge: boolean;
    premium_hours: boolean;
    overtime_gates: boolean;
    missing_side: string;
}
function ToggleSetting({
    label,
    name,
    initial,
    hint,
    error,
}: {
    label: string;
    name: string;
    initial: boolean;
    hint: string;
    error?: string;
}) {
    const [enabled, setEnabled] = useState(initial);
    return (
        <Field label={label} hint={hint} error={error}>
            {({ id, invalid, describedBy }) => (
                <div className="flex items-center gap-3">
                    <input type="hidden" name={'settings[' + name + ']'} value={enabled ? '1' : '0'} />
                    <Checkbox
                        id={id}
                        checked={enabled}
                        onCheckedChange={(value) => setEnabled(value === true)}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                    <span className="text-sm">{enabled ? 'Enabled' : 'Disabled'}</span>
                </div>
            )}
        </Field>
    );
}
export default function AgencySettings({
    settings,
    policy,
    policies,
    workgroups,
    employees,
    templates,
    roles,
    supervisors,
    missingSides,
}: {
    settings: Settings;
    policy: LedgerPolicy | null;
    policies: LedgerPolicy[];
    workgroups: Workgroup[];
    employees: Employee[];
    missingSides: Choice[];
} & PolicyChoices) {
    const [scope, setScope] = useState('workgroup');
    const [subject, setSubject] = useState<string | null>(null);
    const [missingSide, setMissingSide] = useState(settings.missing_side);
    const choices = { templates, roles, supervisors };
    const selectedPolicy = policies.find((item) =>
        scope === 'workgroup' ? item.workgroup_id === subject : item.employee_id === subject,
    );
    const options = (scope === 'workgroup' ? workgroups : employees).map((item) => ({
        value: item.id,
        label: item.name,
    }));
    return (
        <AppLayout>
            <PageHeader
                title="Agency settings"
                description="Defaults for attendance, ledger certification, and PDF archiving."
                actions={
                    <Button type="submit" form="agency-settings">
                        Save settings
                    </Button>
                }
            />
            <Form id="agency-settings" {...update.form()} className="grid max-w-[720px] gap-8" disableWhileProcessing>
                {({ errors }) => (
                    <>
                        {errors.settings && <Alert variant="destructive">{errors.settings}</Alert>}
                        <section className="grid gap-6">
                            <h2 className="text-lg font-semibold">PDF archiving</h2>
                            <ToggleSetting
                                label="Retain attested ledger PDFs"
                                name="ledger_archiving"
                                initial={settings.ledger_archiving}
                                error={errors['settings.ledger_archiving']}
                                hint="Off by default. Verification remains available either way. When enabled, completed attestations also retain an immutable PDF."
                            />
                        </section>
                        <section className="grid gap-6 border-t pt-8">
                            <h2 className="text-lg font-semibold">Agency ledger policy</h2>
                            <p className="text-muted-foreground text-sm">
                                Workgroup and employee overrides inherit each field independently. Changes apply to the
                                next lock.
                            </p>
                            <LedgerPolicyFields policy={policy} choices={choices} errors={errors} prefix="policy" />
                        </section>
                        <section className="grid gap-6 border-t pt-8">
                            <h2 className="text-lg font-semibold">Attendance calculation</h2>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Night window begins" error={errors['settings.night_from']}>
                                    {({ id, invalid, describedBy }) => (
                                        <Input
                                            id={id}
                                            type="time"
                                            name="settings[night_from]"
                                            defaultValue={settings.night_from}
                                            aria-invalid={invalid}
                                            aria-describedby={describedBy}
                                        />
                                    )}
                                </Field>
                                <Field
                                    label="Weekly overtime threshold (hours)"
                                    error={errors['settings.overtime_after_weekly']}
                                    hint="Leave blank when no weekly threshold applies."
                                >
                                    {({ id, invalid, describedBy }) => (
                                        <Input
                                            id={id}
                                            type="number"
                                            min={1}
                                            max={168}
                                            name="settings[overtime_after_weekly]"
                                            defaultValue={settings.overtime_after_weekly ?? ''}
                                            aria-invalid={invalid}
                                            aria-describedby={describedBy}
                                        />
                                    )}
                                </Field>
                            </div>
                            <ChoiceField
                                label="Missing punch side"
                                name="settings[missing_side]"
                                value={missingSide}
                                onChange={setMissingSide}
                                choices={missingSides}
                                error={errors['settings.missing_side']}
                            />
                            <div className="grid gap-6 sm:grid-cols-2">
                                <ToggleSetting
                                    name="occurrences"
                                    label="Count attendance occurrences"
                                    initial={settings.occurrences}
                                    error={errors['settings.occurrences']}
                                    hint="Include tardiness, undertime, and absence counts."
                                />
                                <ToggleSetting
                                    name="suspension_charge"
                                    label="Charge suspended time"
                                    initial={settings.suspension_charge}
                                    error={errors['settings.suspension_charge']}
                                    hint="Apply the agency suspension charging rule."
                                />
                                <ToggleSetting
                                    name="premium_hours"
                                    label="Premium hour credit"
                                    initial={settings.premium_hours}
                                    error={errors['settings.premium_hours']}
                                    hint="Credit the first eight hours on qualifying non-working holidays."
                                />
                                <ToggleSetting
                                    name="overtime_gates"
                                    label="Apply overtime eligibility gates"
                                    initial={settings.overtime_gates}
                                    error={errors['settings.overtime_gates']}
                                    hint="Require the agency's authority and attendance eligibility conditions."
                                />
                            </div>
                        </section>
                    </>
                )}
            </Form>
            <section className="mt-8 grid max-w-[720px] gap-6 border-t pt-8">
                <h2 className="text-lg font-semibold">Policy overrides</h2>
                <p className="text-muted-foreground text-sm">
                    Set an exception for a workgroup or an employee. Clear a field to inherit the next applicable
                    policy.
                </p>
                <ChoiceField
                    label="Override applies to"
                    value={scope}
                    onChange={(value) => {
                        setScope(value);
                        setSubject(null);
                    }}
                    choices={[
                        { value: 'workgroup', label: 'Workgroup' },
                        { value: 'employee', label: 'Employee' },
                    ]}
                />
                <Field label={scope === 'workgroup' ? 'Workgroup' : 'Employee'}>
                    {({ id }) => (
                        <Combobox
                            id={id}
                            value={subject}
                            onValueChange={setSubject}
                            options={options}
                            placeholder="Choose a record"
                        />
                    )}
                </Field>
                {subject && (
                    <Form
                        key={scope + subject}
                        {...(scope === 'workgroup' ? updateWorkgroup.form(subject) : updateEmployee.form(subject))}
                        className="grid gap-6"
                        disableWhileProcessing
                    >
                        {({ errors, processing }) => (
                            <>
                                <LedgerPolicyFields policy={selectedPolicy} choices={choices} errors={errors} />
                                <Button type="submit" variant="outline" className="w-fit" disabled={processing}>
                                    Save override
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </section>
        </AppLayout>
    );
}

import { LockIcon } from 'lucide-react';
import { useId } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { implied } from '@/hooks/use-can';
import type { Permission } from '@/types';

export interface PresetOption {
    value: string;
    label: string;
    permissions: Permission[];
}

/**
 * One row per area of the product, in the order §5.21 lists them, each with
 * the pair of rights that reaches it. `view: null` is an area with no separate
 * view right — the em dash in the artboard — and there are exactly two of
 * them: an agency's own profile and its users.
 *
 * The rows are written out rather than derived from the permission values
 * because the label is the sentence a person reads ("Workgroups, employees,
 * deployments and tags"), not a name the enum holds. Every `Permission`
 * case must appear here or in `ATTEST`, and
 * tests/Unit/PermissionMatrixContractTest.php fails if one does not.
 */
const AREAS: { label: string; view: Permission | null; manage: Permission }[] = [
    { label: 'Agency profile and settings', view: null, manage: 'agency.manage' },
    { label: 'Users and their permissions', view: null, manage: 'users.manage' },
    { label: 'Workgroups, employees, deployments and tags', view: 'organization.view', manage: 'organization.manage' },
    { label: 'Shifts, schedules and rosters', view: 'scheduling.view', manage: 'scheduling.manage' },
    { label: 'Holidays, suspensions, exemptions and overtime', view: 'calendar.view', manage: 'calendar.manage' },
    { label: 'Terminals, enrollments and timelogs', view: 'terminals.view', manage: 'terminals.manage' },
    { label: 'Workdays and daily time records', view: 'ledgers.view', manage: 'ledgers.manage' },
];

/** The one right that is not a view/manage pair, so it sits below the table. */
const ATTEST = {
    permission: 'ledgers.attest' as Permission,
    label: 'Sign daily time records as the timekeeper',
    hint: 'Puts their name on CS Form 48 when a ledger is attested. Only the officer who signs needs this.',
};

/**
 * A box in a View or Manage column.
 *
 * A locked box is `aria-disabled`, not `disabled`: a disabled control leaves
 * the tab order, and the whole point of the lock is that someone arriving by
 * keyboard learns why the box cannot be unchecked. It stays focusable,
 * announces itself as checked and unavailable, and — being controlled with a
 * fixed `checked` — cannot change.
 */
function Box({
    permission,
    checked,
    lockedBy,
    invalid,
    onToggle,
}: {
    permission: Permission;
    checked: boolean;
    /** The manage right that already grants this view, when one does. */
    lockedBy?: Permission;
    invalid: boolean;
    onToggle: (checked: boolean) => void;
}) {
    if (lockedBy) {
        return (
            <span className="inline-flex items-center gap-1.5">
                <Checkbox
                    checked
                    aria-disabled
                    aria-label={`${permission}, implied by ${lockedBy}`}
                    onCheckedChange={() => undefined}
                />
                <LockIcon aria-hidden strokeWidth={1.5} className="text-muted-foreground size-3.5" />
            </span>
        );
    }

    return (
        <Checkbox
            checked={checked}
            aria-label={permission}
            aria-invalid={invalid || undefined}
            onCheckedChange={(next) => onToggle(next === true)}
        />
    );
}

/**
 * The invite and edit forms' core: a real `<table>` so every box is announced
 * with its row and its column, inside the one panel a matrix earns (§5.21).
 *
 * A checked manage right also checks its implied view, because the backend
 * grants it anyway (`Permission::implies()`) and leaving it unchecked would
 * misrepresent what the person can actually do. That box is locked rather
 * than merely drawn checked: unchecking it on its own could never revoke the
 * grant. `implied` comes from useCan()'s own map, which
 * tests/Unit/Enums/PermissionContractTest.php holds to the PHP enum, so this
 * never becomes a third copy of that relationship.
 *
 * Only the rights the person holds directly are submitted. An implied view is
 * never posted, exactly as a preset bundle does not carry one.
 */
export function PermissionMatrix({
    value,
    onChange,
    presets,
    error,
}: {
    value: Permission[];
    onChange: (value: Permission[]) => void;
    presets: PresetOption[];
    /** The one-line verdict for the label row: `Choose at least one`. */
    error?: string;
}) {
    const labelId = useId();
    const errorId = `${labelId}-error`;

    const activePreset =
        presets.find(
            (preset) =>
                preset.permissions.length === value.length &&
                preset.permissions.every((permission) => value.includes(permission)),
        )?.value ?? 'custom';

    /** The manage (or attest) right that grants each implied view, by view. */
    const locks = new Map<Permission, Permission>();
    for (const held of value) {
        const view = implied[held];
        if (view && !locks.has(view)) {
            locks.set(view, held);
        }
    }

    function toggle(permission: Permission, checked: boolean) {
        onChange(checked ? [...value, permission] : value.filter((held) => held !== permission));
    }

    return (
        <div className="mt-8">
            <div className="flex items-baseline justify-between gap-3 pb-1.5">
                <span id={labelId} className="text-[13px] leading-[18px] font-medium">
                    Permissions
                </span>
                {/* Always rendered, and never empty — see field.tsx for why
                    the zero-width space is what keeps the row's height fixed. */}
                <p
                    id={errorId}
                    aria-live="polite"
                    className="text-destructive min-h-[18px] text-right text-[13px] leading-[18px] font-medium"
                >
                    {error || '\u200b'}
                </p>
            </div>
            <div className="border-border rounded-xl border p-5">
                <div className="flex items-center gap-3">
                    <span className="text-muted-foreground text-[13px] leading-[18px]">Start from a preset</span>
                    <span className="flex-1" />
                    <ToggleGroup
                        type="single"
                        aria-label="Permission preset"
                        value={activePreset}
                        onValueChange={(next) => {
                            const preset = presets.find((candidate) => candidate.value === next);

                            if (preset) {
                                onChange(preset.permissions);
                            }
                        }}
                    >
                        {presets.map((preset) => (
                            <ToggleGroupItem key={preset.value} value={preset.value}>
                                {preset.label}
                            </ToggleGroupItem>
                        ))}
                        {/*
                          Not a choice — a status, lit whenever the checked set
                          matches no preset above. There is no set "Custom"
                          would select, so it is never selectable; it keeps its
                          full contrast because a status at 45% opacity reads
                          as broken rather than as inactive.
                        */}
                        <ToggleGroupItem value="custom" disabled className="disabled:opacity-100">
                            Custom
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>
                <table
                    className="w-full border-separate border-spacing-0"
                    aria-labelledby={labelId}
                    aria-describedby={error ? errorId : undefined}
                >
                    <thead>
                        <tr className="text-muted-foreground text-xs leading-4 font-semibold">
                            <th scope="col" className="h-[34px] pb-1.5 text-left align-bottom">
                                What they can reach
                            </th>
                            <th scope="col" className="h-[34px] w-[86px] pb-1.5 text-center align-bottom">
                                View
                            </th>
                            <th scope="col" className="h-[34px] w-[86px] pb-1.5 text-center align-bottom">
                                Manage
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {AREAS.map((area) => {
                            const view = area.view;
                            const lockedBy = view ? locks.get(view) : undefined;

                            return (
                                <tr key={area.manage}>
                                    <th
                                        scope="row"
                                        className="border-rule [tbody_tr:first-child_&]:border-border h-11 border-t text-left align-middle text-sm leading-5 font-normal"
                                    >
                                        {area.label}
                                    </th>
                                    <td className="border-rule [tbody_tr:first-child_&]:border-border h-11 border-t text-center align-middle">
                                        {view ? (
                                            <Box
                                                permission={view}
                                                checked={value.includes(view)}
                                                lockedBy={lockedBy}
                                                invalid={Boolean(error)}
                                                onToggle={(checked) => toggle(view, checked)}
                                            />
                                        ) : (
                                            <span className="text-muted-foreground" aria-label="No separate view right">
                                                &mdash;
                                            </span>
                                        )}
                                    </td>
                                    <td className="border-rule [tbody_tr:first-child_&]:border-border h-11 border-t text-center align-middle">
                                        <Box
                                            permission={area.manage}
                                            checked={value.includes(area.manage)}
                                            invalid={Boolean(error)}
                                            onToggle={(checked) => toggle(area.manage, checked)}
                                        />
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                <div className="border-border mt-1 flex items-start gap-2.5 border-t pt-4">
                    <Checkbox
                        id={`${labelId}-attest`}
                        checked={value.includes(ATTEST.permission)}
                        aria-invalid={error ? true : undefined}
                        onCheckedChange={(next) => toggle(ATTEST.permission, next === true)}
                        className="mt-0.5"
                    />
                    <span>
                        <label htmlFor={`${labelId}-attest`} className="text-sm leading-5">
                            {ATTEST.label}
                        </label>
                        <p className="text-muted-foreground pt-[3px] text-xs leading-4">{ATTEST.hint}</p>
                    </span>
                </div>
            </div>
            {value.map((permission) => (
                <input key={permission} type="hidden" name="permissions[]" value={permission} />
            ))}
        </div>
    );
}

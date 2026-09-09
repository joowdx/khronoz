import { implied } from '@/hooks/use-can';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { Permission } from '@/types';

export interface PermissionOption {
    value: Permission;
    label: string;
    group: string;
}

export interface PresetOption {
    value: string;
    label: string;
    permissions: Permission[];
}

/**
 * A checked "manage" permission also checks its implied "view" — the backend
 * grants it anyway (Permission::implies()), so leaving it unchecked would
 * misrepresent what the user can actually do. That box is also disabled,
 * since unchecking it on its own could never revoke the grant.
 *
 * `implied` comes from useCan()'s own map (resources/js/hooks/use-can.ts,
 * kept in step with Permission::implies() by comments in both files) so this
 * never grows into a third copy of that relationship.
 */
export function PermissionPicker({
    value,
    onChange,
    presets,
    permissions,
}: {
    value: Permission[];
    onChange: (value: Permission[]) => void;
    presets: PresetOption[];
    permissions: PermissionOption[];
}) {
    const activePreset =
        presets.find(
            (preset) =>
                preset.permissions.length === value.length &&
                preset.permissions.every((permission) => value.includes(permission)),
        )?.value ?? 'custom';

    const groups = new Map<string, PermissionOption[]>();
    for (const permission of permissions) {
        const list = groups.get(permission.group) ?? [];
        list.push(permission);
        groups.set(permission.group, list);
    }

    function toggle(permission: Permission, checked: boolean) {
        onChange(checked ? [...value, permission] : value.filter((held) => held !== permission));
    }

    return (
        <div className="grid gap-4">
            <ToggleGroup
                type="single"
                variant="outline"
                value={activePreset}
                onValueChange={(next) => {
                    const preset = presets.find((candidate) => candidate.value === next);
                    if (preset) onChange(preset.permissions);
                }}
            >
                {presets.map((preset) => (
                    <ToggleGroupItem key={preset.value} value={preset.value}>
                        {preset.label}
                    </ToggleGroupItem>
                ))}
                {/* Not a real choice — a status indicator lit whenever the checked set doesn't match any preset above. */}
                <ToggleGroupItem value="custom" disabled>
                    Custom
                </ToggleGroupItem>
            </ToggleGroup>
            <div className="grid gap-3 sm:grid-cols-2">
                {Array.from(groups.entries()).map(([group, groupPermissions]) => (
                    <div key={group} className="grid gap-2 rounded-md border p-3">
                        <span className="text-sm font-medium">{group}</span>
                        {groupPermissions.map((permission) => {
                            const heldDirectly = value.includes(permission.value);
                            const impliedOnly =
                                !heldDirectly && value.some((held) => implied[held] === permission.value);
                            const id = `permission-${permission.value}`;

                            return (
                                <div key={permission.value} className="flex items-start gap-2">
                                    <Checkbox
                                        id={id}
                                        checked={heldDirectly || impliedOnly}
                                        disabled={impliedOnly}
                                        onCheckedChange={(checked) => toggle(permission.value, checked === true)}
                                        className="mt-0.5"
                                    />
                                    <Label htmlFor={id} className="text-sm font-normal">
                                        {permission.label}
                                    </Label>
                                </div>
                            );
                        })}
                    </div>
                ))}
            </div>
            {value.map((permission) => (
                <input key={permission} type="hidden" name="permissions[]" value={permission} />
            ))}
        </div>
    );
}

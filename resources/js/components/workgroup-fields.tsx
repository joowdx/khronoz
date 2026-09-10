import { useState } from 'react';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import { flattenWorkgroups, subtreeIds } from '@/lib/workgroups';
import type { Employee, Workgroup } from '@/types';

/**
 * The five columns of `workgroups`, in one 560px column — shared by the add and the
 * edit screens for the same reason the employee fields are.
 *
 * Two of the five are pickers rather than text boxes, and both are searchable
 * (§5.14's popover with cmdk) because both lists are as long as the agency is
 * big. Each writes a hidden input, so the page's Inertia `<Form>` submits it
 * without knowing they hold state.
 *
 * The parent picker refuses the workgroup itself and everything under it. A cycle
 * is refused by `workgroups_parent_not_self` and the `workgroups_acyclic` trigger, and
 * StoreWorkgroupRequest deliberately leaves it to them — which is exactly why the
 * picker has to make it unreachable: the database's refusal arrives as an
 * unhandled SQLSTATE, not as a message on a label row.
 */
export function WorkgroupFields({
    workgroup,
    workgroups,
    employees,
    errors,
}: {
    /** The workgroup being edited, or nothing when one is being added. */
    workgroup?: Workgroup;
    /** Every workgroup of the agency, flat — the parent picker's options. */
    workgroups: Workgroup[];
    /** Who may head a workgroup: employees still employed (WorkgroupController::heads). */
    employees: Employee[];
    errors: Partial<Record<string, string>>;
}) {
    const [parent, setParent] = useState<string | null>(workgroup?.parent_id ?? null);
    const [head, setHead] = useState<string | null>(workgroup?.head_id ?? null);

    const forbidden = workgroup ? subtreeIds(workgroup.id, workgroups) : new Set<string>();
    const parents = flattenWorkgroups(workgroups).filter(({ workgroup: option }) => !forbidden.has(option.id));

    return (
        <>
            <Field label="Name" htmlFor="name" error={errors.name}>
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="name"
                        defaultValue={workgroup?.name}
                        autoFocus
                        placeholder="Administrative Division"
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>

            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-[180px_1fr]">
                <Field
                    label="Code"
                    htmlFor="code"
                    error={errors.code}
                    hint="Unique in your agency."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="code"
                            defaultValue={workgroup?.code}
                            placeholder="ADMIN"
                            maxLength={255}
                            className="uppercase"
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field
                    label="Kind"
                    htmlFor="kind"
                    error={errors.kind}
                    hint="Whatever your agency calls this level: department, division, section, unit."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="kind"
                            defaultValue={workgroup?.kind ?? ''}
                            placeholder="division"
                            maxLength={255}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </div>

            <Field
                className="mt-6"
                label="Sits under"
                htmlFor="parent_id"
                error={errors.parent_id}
                hint="Leave it at the top level for the one workgroup everything else hangs off."
            >
                {({ id, invalid, describedBy }) => (
                    <Combobox
                        id={id}
                        name="parent_id"
                        value={parent}
                        onValueChange={setParent}
                        invalid={invalid}
                        describedBy={describedBy}
                        placeholder="Top level"
                        searchPlaceholder="Search workgroups"
                        empty="No workgroup by that name."
                        clearLabel="Top level"
                        options={parents.map(({ workgroup: option, depth }) => ({
                            value: option.id,
                            label: option.name,
                            keywords: [option.code, option.kind ?? ''],
                            trigger: option.name,
                            render: (
                                <span className="flex min-w-0 items-center" style={{ paddingLeft: depth * 14 }}>
                                    <span className="truncate">{option.name}</span>
                                    <span className="text-muted-foreground ml-2 shrink-0 text-xs">{option.code}</span>
                                </span>
                            ),
                        }))}
                    />
                )}
            </Field>

            <Field
                className="mt-6"
                label="Head"
                htmlFor="head_id"
                error={errors.head_id}
                hint="Who runs it. One person can head more than one workgroup, and you can set this later."
            >
                {({ id, invalid, describedBy }) => (
                    <Combobox
                        id={id}
                        name="head_id"
                        value={head}
                        onValueChange={setHead}
                        invalid={invalid}
                        describedBy={describedBy}
                        placeholder="No head yet"
                        searchPlaceholder="Search employees"
                        empty="Nobody by that name."
                        clearLabel="No head"
                        options={employees.map((employee) => ({
                            value: employee.id,
                            label: employee.name,
                            keywords: [employee.number, employee.position ?? ''],
                            trigger: employee.name,
                            render: (
                                <span className="flex min-w-0 items-baseline gap-2">
                                    <span className="truncate">{employee.name}</span>
                                    <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                                        {employee.number}
                                    </span>
                                </span>
                            ),
                        }))}
                    />
                )}
            </Field>
        </>
    );
}

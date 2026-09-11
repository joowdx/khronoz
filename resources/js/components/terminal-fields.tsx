import { useState } from 'react';
import { Combobox } from '@/components/combobox';
import { Field } from '@/components/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { flattenWorkgroups } from '@/lib/workgroups';
import type { Terminal, Workgroup } from '@/types';

/**
 * The fields of `terminals` an operator actually sets, in one 560px column —
 * shared by the add and the edit screens the way the workgroup fields are.
 *
 * **Not every column is here, and the omissions are the design.** `host`,
 * `port` and `secret` belong to push and pull, which Milestone 5 does not
 * ship (decision 40); offering them would invite someone to configure a
 * connection nothing will ever open. `stamp`, `seen_at` and `synced_at` are
 * written by a device read and by nothing else — an import must not move them
 * — so they are facts the list reports, never fields a person fills in.
 *
 * `code` carries the hardest hint on the page because it is the one field that
 * can silently ruin an import. It is compared byte-for-byte against the
 * `device` column of every attlog line (decision 44): a file that names a
 * different device is refused whole, and a file naming *two* is refused as
 * tampering. So the hint tells the operator where to read the number from
 * rather than inviting them to make one up.
 */
export function TerminalFields({
    terminal,
    workgroups,
    errors,
}: {
    /** The terminal being edited, or nothing when one is being added. */
    terminal?: Terminal;
    /** Every workgroup of the agency, flat — the "sits at" picker's options. */
    workgroups: Workgroup[];
    errors: Partial<Record<string, string>>;
}) {
    const [workgroup, setWorkgroup] = useState<string | null>(terminal?.workgroup_id ?? null);
    const [kind, setKind] = useState<string>(terminal?.kind ?? 'terminal');
    const [protocol, setProtocol] = useState<string>(terminal?.protocol ?? 'file');

    const places = flattenWorkgroups(workgroups);

    return (
        <>
            <Field label="Name" htmlFor="name" error={errors.name}>
                {({ id, invalid, describedBy }) => (
                    <Input
                        id={id}
                        name="name"
                        defaultValue={terminal?.name}
                        autoFocus
                        placeholder="Lobby entrance"
                        maxLength={255}
                        aria-invalid={invalid}
                        aria-describedby={describedBy}
                    />
                )}
            </Field>

            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-[180px_1fr]">
                <Field
                    label="Device number"
                    htmlFor="code"
                    error={errors.code}
                    hint="As the device itself reports it."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="code"
                            defaultValue={terminal?.code}
                            placeholder="1"
                            maxLength={255}
                            className="tabular-nums"
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
                <Field
                    label="Serial"
                    htmlFor="serial"
                    error={errors.serial}
                    hint="Optional. From the sticker on the back — unique across every agency."
                >
                    {({ id, invalid, describedBy }) => (
                        <Input
                            id={id}
                            name="serial"
                            defaultValue={terminal?.serial ?? ''}
                            placeholder="CGT9230160500"
                            maxLength={255}
                            aria-invalid={invalid}
                            aria-describedby={describedBy}
                        />
                    )}
                </Field>
            </div>

            <div className="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field label="Kind" htmlFor="kind" error={errors.kind}>
                    {({ id, invalid, describedBy }) => (
                        <Combobox
                            id={id}
                            name="kind"
                            value={kind}
                            onValueChange={(next) => setKind(next ?? 'terminal')}
                            invalid={invalid}
                            describedBy={describedBy}
                            placeholder="Networked terminal"
                            searchPlaceholder="Search kinds"
                            empty="No such kind."
                            options={[
                                { value: 'terminal', label: 'Networked terminal', trigger: 'Networked terminal' },
                                { value: 'usb', label: 'Offline device', trigger: 'Offline device' },
                            ]}
                        />
                    )}
                </Field>
                <Field
                    label="How punches arrive"
                    htmlFor="protocol"
                    error={errors.protocol}
                    hint="Only file import is built so far."
                >
                    {({ id, invalid, describedBy }) => (
                        <Combobox
                            id={id}
                            name="protocol"
                            value={protocol}
                            onValueChange={(next) => setProtocol(next ?? 'file')}
                            invalid={invalid}
                            describedBy={describedBy}
                            placeholder="File import"
                            searchPlaceholder="Search"
                            empty="No such method."
                            options={[
                                { value: 'file', label: 'File import', trigger: 'File import' },
                                { value: 'pull', label: 'khronoz pulls', trigger: 'khronoz pulls' },
                                { value: 'push', label: 'Device pushes', trigger: 'Device pushes' },
                            ]}
                        />
                    )}
                </Field>
            </div>

            <Field
                className="mt-6"
                label="Sits at"
                htmlFor="workgroup_id"
                error={errors.workgroup_id}
                hint="Leave it agency-wide for a device in a shared lobby."
            >
                {({ id, invalid, describedBy }) => (
                    <Combobox
                        id={id}
                        name="workgroup_id"
                        value={workgroup}
                        onValueChange={setWorkgroup}
                        invalid={invalid}
                        describedBy={describedBy}
                        placeholder="Agency-wide"
                        searchPlaceholder="Search workgroups"
                        empty="No workgroup by that name."
                        clearLabel="Agency-wide"
                        options={places.map(({ workgroup: option, depth }) => ({
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
                label="In service"
                htmlFor="active"
                hint="Turn this off for a device that has been retired. Its punches stay."
            >
                {({ id }) => (
                    <>
                        <input type="hidden" name="active" value="0" />
                        <Switch id={id} name="active" value="1" defaultChecked={terminal?.active ?? true} />
                    </>
                )}
            </Field>
        </>
    );
}

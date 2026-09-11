export type Permission =
    | 'agency.manage'
    | 'users.manage'
    | 'organization.view'
    | 'organization.manage'
    | 'scheduling.view'
    | 'scheduling.manage'
    | 'calendar.view'
    | 'calendar.manage'
    | 'terminals.view'
    | 'terminals.manage'
    | 'ledgers.view'
    | 'ledgers.manage'
    | 'ledgers.attest';

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    permissions: Permission[];
    platform: boolean;
    employee_id: string | null;
}
export interface PermissionGroupEntry {
    value: Permission;
    label: string;
}
/**
 * Matches UserResource. `permissions` stays a flat array like AuthUser's,
 * for the same reason; `permission_groups` is the second, additive, grouped
 * representation Task 9 adds for display (the users list's access tooltip).
 */
export interface User {
    id: string;
    name: string;
    email: string;
    permissions: Permission[];
    permission_groups: Record<string, PermissionGroupEntry[]>;
    platform: boolean;
    employee_id: string | null;
    invited_at: string | null;
    email_verified_at: string | null;
}
export interface Agency {
    id: string;
    code: string;
    name: string;
    platform: boolean;
}

/** employees.sex is a nullable varchar plus a CHECK; null is "not recorded". */
export type Sex = 'male' | 'female';

/**
 * Matches EmployeeResource.
 *
 * The five date columns arrive as plain `YYYY-MM-DD` strings, never as ISO
 * instants — the resources call `->toDateString()` precisely because a
 * date-cast attribute JSON-serializes as UTC midnight, which under
 * Asia/Manila always names the day before. So they bind straight to
 * `<input type="date">` and must never be run back through `new Date(...)`.
 *
 * `current_deployment` and `deployments` are `whenLoaded`, so the key is
 * **absent** when the controller did not eager-load it and `null` when it
 * loaded and there is nothing there — two different facts, hence the optional
 * *and* nullable type. `current_deployment` comes from ::index and ::show;
 * `deployments` (the whole history) only from ::show.
 */
export interface Employee {
    id: string;
    number: string;
    /** first + middle + last + suffix, composed by the model. */
    name: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: string | null;
    sex: Sex | null;
    birthdate: string | null;
    email: string | null;
    mobile: string | null;
    position: string | null;
    /** Free-form agency labels, read by no rule (01-organization.md rule 5). */
    tags: string[];
    /** No daily time record expected. */
    exempt: boolean;
    current_deployment?: Deployment | null;
    deployments?: Deployment[];
}

/**
 * Matches WorkgroupResource. The tree is composed client-side from a flat list
 * keyed by `parent_id` (see lib/workgroups.ts), so there is no nested `children`
 * here — the resource does not send one.
 *
 * `head` is `whenLoaded`: absent means the controller did not ask for it,
 * `null` means the workgroup has no head. `people_count` is a `withCount`
 * aggregate present only on the workgroups index, so it lives in that page's own
 * row type rather than here — the same split `AgencyRow` uses for
 * `users_count`.
 */
export interface Workgroup {
    id: string;
    parent_id: string | null;
    /** "department", "division", "section"… label only, agency-defined. */
    kind: string | null;
    code: string;
    name: string;
    head_id: string | null;
    head?: Employee | null;
}

/**
 * Matches `TerminalResource`. A biometric device.
 *
 * `code` is the device number **as a string** and must stay one (decision 42):
 * it is what the attlog stamps into every line, compared byte-for-byte at
 * import, and `007` is not `7`.
 *
 * There is no `secret`. The comm key never leaves the database (decision 40);
 * `has_secret` says only whether one is set.
 */
export interface Terminal {
    id: string;
    workgroup_id: string | null;
    workgroup?: Workgroup | null;
    /** The device number the attlog carries. A string, never a number. */
    code: string;
    name: string;
    serial: string | null;
    /** "terminal" (networked) or "usb" (carried across on a stick). */
    kind: string;
    /** "push", "pull" or "file" — only "file" has a writer today (decision 40). */
    protocol: string;
    host: string | null;
    port: number | null;
    /** Whether a comm key is set. Never the key itself. */
    has_secret: boolean;
    active: boolean;
    /** `YYYY-MM-DD HH:MM:SS` or null; never reparsed client-side. */
    seen_at: string | null;
    synced_at: string | null;
    /** The read offset for a future pull. An import never moves it. */
    stamp: string | null;
}

/**
 * Matches `EnrollmentResource`. Which employee is which device user id on which
 * terminal, and when.
 *
 * A **date range**, not a flag: `covering(date)` has at most one answer,
 * which is what lets the database resolve a punch deterministically. `uid` is
 * an opaque string (decision 42) — `007` is not `7`. `starts`/`ends` are
 * `YYYY-MM-DD` and are never reparsed (`lib/dates.ts`).
 */
export interface Enrollment {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    terminal_id: string;
    /** The device user id, exactly as the device reports it. */
    uid: string;
    privilege: string;
    starts: string;
    ends: string | null;
    /** Whether punches resolve through this row today. */
    current: boolean;
}

/**
 * Matches `TimelogResource`. What a device recorded — never a punch, which is
 * a matched slot side of a workday and belongs to Milestone 6.
 *
 * `state` and `mode` are the **raw attlog integers** (03-terminals.md rule 6).
 * An unfamiliar firmware emits codes the documented table does not list, so
 * they are labelled where recognised and printed as numbers otherwise.
 *
 * `employee_id` null means **unresolved**: the uid matched no enrollment on
 * that date. Normal, visible, and never hidden.
 *
 * `time` is the device's own naive wall clock — never converted, never
 * adjusted — so it is a string and is never reparsed.
 */
export interface Timelog {
    id: string;
    terminal_id: string;
    terminal?: Terminal | null;
    uid: string;
    /** `YYYY-MM-DD HH:MM:SS`, as the device reported it. */
    time: string;
    state: number;
    mode: number;
    source: string;
    employee_id: string | null;
    employee?: Employee | null;
    voided_at: string | null;
    reason: string | null;
}

/**
 * Matches `SyncResource`. One ingestion run, successful or refused.
 *
 * The four counters are balanced by `syncs_counts_balance`, so
 * `accepted + duplicates + rejected === received` can be relied on without
 * the page checking. `received` is rows **accounted for**, not lines read —
 * on a run that died mid-chunk the difference is real and the status says so.
 *
 * `error` on a failed run carries the reason, and a file refused for naming
 * two devices is the closest thing this system has to a tamper alert.
 */
export interface Sync {
    id: string;
    terminal_id: string;
    terminal?: Terminal | null;
    /** "scheduled", "manual", "push" or "import" — only import has a writer today. */
    trigger: string;
    /** "running", "completed" or "failed". */
    status: string;
    started_at: string;
    finished_at: string | null;
    received: number;
    accepted: number;
    duplicates: number;
    rejected: number;
    /** The source filename, for an import. */
    reference: string | null;
    earliest: string | null;
    latest: string | null;
    error: string | null;
}

/**
 * Matches `HolidayResource`. A date on which no work is expected, or on which
 * work is paid at a premium.
 *
 * **A date may carry more than one**, and both are owed — Eid al-Fitr on
 * Bonifacio Day is two holidays at the higher rate (dole-rules.md I.6). Every
 * read of this list is plural; never reach for the first row on a date.
 *
 * `national` means the platform agency declared it for everyone, so this
 * agency may read it and not change it.
 */
export interface Holiday {
    id: string;
    /** `YYYY-MM-DD`; never reparsed (`lib/dates.ts`). */
    date: string;
    name: string;
    /** "regular", "special", "working" or "local" — a rate, not a scope. */
    type: string;
    reference: string | null;
    declared_at: string;
    national: boolean;
}

/**
 * Matches `SuspensionResource`. Work suspended for a day or part of one.
 *
 * `workgroup` null is **agency-wide**, not missing, and is the commonest shape
 * — a typhoon closes the office, not one division. Naming a workgroup means
 * that workgroup and everything under it.
 *
 * `starts`/`ends` null together is the whole day; the pair is held by
 * `suspensions_hours_paired`, so one being set means both are.
 */
export interface Suspension {
    id: string;
    workgroup_id: string | null;
    workgroup?: Workgroup | null;
    date: string;
    /** `HH:MM:SS` or null. Null on both means the whole day. */
    starts: string | null;
    ends: string | null;
    reason: string;
    reference: string | null;
    declared_at: string;
    user?: { id: string; name: string } | null;
}

/**
 * Matches DeploymentResource. One placement of one employee in one workgroup over
 * a date range; `ends: null` is the open, current one. Carries no `employee`:
 * it only ever appears nested under the employee it belongs to.
 *
 * `parent_id` null is the substantive placement, where the plantilla item
 * sits; set means this row is a reassignment nested inside that placement,
 * which stays open because the item never left (decision 31). There is no
 * type field — the presence of a parent is the whole fact.
 */
export interface Deployment {
    id: string;
    workgroup?: Workgroup | null;
    parent_id: string | null;
    starts: string;
    ends: string | null;
}
export interface Flash {
    success?: string;
    error?: string;
}
export interface SharedProps {
    auth: { user: AuthUser | null };
    agency: Agency | null;
    agencies: Agency[];
    flash: Flash;
    [key: string]: unknown;
}
export interface BreadcrumbItem {
    title: string;
    href?: string;
}

/** The three colour-mode choices. `system` follows the OS and keeps following it. */
export type Appearance = 'light' | 'dark' | 'system';

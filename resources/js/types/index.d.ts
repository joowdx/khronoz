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
/**
 * One value of a PHP enum, with the words that enum's own `label()` returns.
 *
 * The labels are never restated in TypeScript. An enum's cases are held to the
 * database's CHECK by `EnumCheckContractTest`, so a hand-written map here
 * would be a third copy nothing holds to the other two — and it drifts
 * silently, showing a value the CHECK refuses or missing one it allows.
 */
export interface Choice {
    value: string;
    label: string;
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

/**
 * employees.sex is a nullable varchar plus a CHECK; null is "not recorded".
 * The union is kept for the *value*, which forms and filters compare against;
 * the display words come from the enum as a `Choice` (.ai/rules/resources.md).
 */
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
    sex: Choice | null;
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
    privilege: Choice;
    starts: string;
    ends: string | null;
    /** Whether punches resolve through this row today. */
    current: boolean;
}

/**
 * Matches `TimelogResource`. What a device recorded — never a punch, which is
 * a matched slot side of a workday and belongs to Milestone 6.
 *
 * `state` and `mode` each carry the **raw attlog integer** (03-terminals.md
 * rule 6) beside the label the server made of it. An unfamiliar firmware emits
 * codes the documented table does not list, so an unrecognised one arrives
 * labelled as its own number. The page holds no vocabulary of its own — that
 * lives on `AttlogState` and `AttlogMode` (.ai/rules/resources.md).
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
    /**
     * The raw attlog integer with the label the server made of it. An
     * undocumented code arrives labelled as its own number — the enum is a
     * reading of `value`, never a constraint on it (03-terminals.md rule 6).
     */
    state: { value: number; label: string };
    mode: { value: number; label: string };
    source: string;
    employee_id: string | null;
    employee?: Employee | null;
    voided_at: string | null;
    reason: string | null;
    /** Who struck it out. Present exactly when `voided_at` is. */
    voider?: { id: string; name: string } | null;
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
    /** Only `import` has a writer today. Labelled by the enum. */
    trigger: Choice;
    /**
     * "running", "completed" or "failed" — a bare value on purpose. The page
     * branches on it and writes its own sentence ("Refused — …", "Still
     * running") rather than printing a label, so there is no vocabulary here
     * to drift.
     */
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
    /** A rate, not a scope. Labelled by the enum, never by the page. */
    type: Choice;
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
 * Matches `ExemptionResource`. Why somebody was away and it is excused.
 *
 * `date .. until` is inclusive on both sides and `until` is never null
 * (decision 38) — a one-day exemption is `until = date`. RA 11210's 105
 * continuous days is one row, not 105.
 *
 * `starts`/`ends` are hours within a single day, and a multi-day exemption
 * cannot carry them: a 10:00–14:00 window repeated across a statutory leave is
 * not what any order means.
 */
export interface Exemption {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    date: string;
    /** The last day, inclusive. */
    until: string;
    type: Choice;
    /** `HH:MM:SS` or null. Single-day exemptions only. */
    starts: string | null;
    ends: string | null;
    reference: string | null;
    remarks: string | null;
    approved_at: string;
    spans_days: boolean;
}

/**
 * Matches `OvertimeResource`. Work authorised beyond the shift.
 *
 * `starts`/`ends` are full timestamps, not a date and two clock times: an
 * authorisation routinely crosses midnight and 22:00–02:00 is one stretch of
 * work. `date` is generated from `starts`, so an overnight stretch belongs to
 * the day it began on — which is how a DTR reads it.
 */
export interface Overtime {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    /** `starts::date`, generated. Grouping only; never written. */
    date: string;
    starts: string;
    ends: string;
    purpose: string;
    /** `pay` or `cto` — the two the CHECK allows, and nothing else. */
    mode: Choice;
    reference: string | null;
    overnight: boolean;
}

/**
 * Matches `PunchResource`. One transit of a workday: an expected slot side
 * and the tap that filled it, a side nothing filled, or a tap that answered
 * no expectation.
 */
export interface Punch {
    id: string;
    slot: number;
    kind: Choice;
    /** `YYYY-MM-DD HH:MM:SS`. Null on a day that expected nothing (decision 78). */
    expected_at: string | null;
    /** Null means no tap filled this side. Never engine-invented (decision 64). */
    actual_at: string | null;
    /** Minutes from `expected_at`; negative is early. Null unless both instants exist. */
    deviation: number | null;
    timelog_id: string | null;
}

/**
 * Matches `WorkdayResource`. One employee-day: the DTR line.
 *
 * `shift_name` is the frozen snapshot, never the live `shifts` row (Workday
 * rule 2). Minute figures are integers; `date` is `YYYY-MM-DD` and is never
 * reparsed (`lib/dates.ts`).
 */
export interface Workday {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    /** `YYYY-MM-DD`. */
    date: string;
    status: Choice;
    /** Null on an ordinary day (decision 63 — and a roster gap is ordinary). */
    premium: Choice | null;
    worked: number;
    credited: number;
    tardy: number;
    undertime: number;
    excess: number;
    night: number;
    night_excess: number;
    punches?: Punch[];
    /**
     * Stamped exemption, when one applies. A personal slip is still recorded
     * and printed (06-attendance.md daily rule 7). Absent when not loaded.
     */
    exemption?: { id: string; type: Choice; reference: string | null } | null;
    /** Null when no shift was rostered. The frozen name, not the live one. */
    shift_name: string | null;
    computed_at: string;
}

/**
 * Matches `LedgerResource`. One employee-month: the DTR page with a lock on
 * it. Totals are not stored; the index adds aggregates of its own, and the
 * DTR page sends a `LedgerView`.
 */
export interface Ledger {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    /** `YYYY-MM-DD`, first of the month. */
    month: string;
    /** `YYYY-MM-DD HH:MM:SS` when frozen; null while open. */
    locked_at: string | null;
}

/**
 * One ledger read: the workdays of a period, their minute totals, the monthly
 * occurrence counts, and the compensable overtime. Produced by `Ledger::view()`
 * and never stored.
 */
export interface LedgerView {
    workdays: Workday[];
    worked: number;
    credited: number;
    tardy: number;
    undertime: number;
    excess: number;
    night: number;
    night_excess: number;
    overtime: number;
    tardy_occurrences: number;
    undertime_occurrences: number;
    absences: number;
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
/**
 * One expected stretch of a shift's day. Times are `"HH:MM"` and may run past
 * 24:00 — `"30:00"` is 06:00 the next day, capped at 72:00 — so they are
 * rendered as given and never reparsed into a Date (04-scheduling.md).
 */
export interface ShiftSlot {
    in: string;
    out: string;
    /** Minutes after `in` still counted on time. */
    grace?: number;
    /** [before `in`, after `out`] in minutes; the first is <= 0, the second >= 0. */
    window: [number, number];
}

export interface Shift {
    id: string;
    name: string;
    slots: ShiftSlot[];
    /** Minutes the day must credit. */
    required: number;
    /** Flexitime slide, in minutes. 0 for a fixed shift. */
    flex: number;
    remote: boolean;
    trust: boolean;
    /** 1 to 8 — the stored ramp index, never derived from name or id. */
    color: number;
    /** Derived server-side: no slots means `off`, unless `remote`. */
    kind: 'working' | 'off' | 'remote';
    origin_id: string | null;
    origin?: Shift | null;
}

export interface Turn {
    id: string;
    /** 0-based; a schedule's turns occupy 0..length-1 exactly. */
    position: number;
    shift_id: string;
    shift?: Shift;
}

export interface Schedule {
    id: string;
    name: string;
    /** Cycle length in days, 1 to 366. */
    length: number;
    fallback_shift_id: string | null;
    fallback_shift?: Shift | null;
    turns?: Turn[];
    origin_id: string | null;
    origin?: Schedule | null;
}

export interface Team {
    id: string;
    name: string;
    schedule_id: string;
    schedule?: Schedule;
    anchor: string;
    people_count?: number;
}

export interface Roster {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    schedule_id: string;
    schedule?: Schedule;
    team_id: string | null;
    team?: Team | null;
    /** Cycle day 1. Independent of `starts`. */
    anchor: string;
    starts: string;
    /** null means still running. */
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

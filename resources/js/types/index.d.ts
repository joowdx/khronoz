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
export interface Choice {
    value: string;
    label: string;
}

export interface PermissionGroupEntry {
    value: Permission;
    label: string;
}
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

export type Sex = 'male' | 'female';

export interface Employee {
    id: string;
    number: string;
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
    tags: string[];
    exempt: boolean;
    cadence_id: string | null;
    current_deployment?: Deployment | null;
    deployments?: Deployment[];
}

export interface Workgroup {
    id: string;
    parent_id: string | null;
    kind: string | null;
    code: string;
    name: string;
    head_id: string | null;
    head?: Employee | null;
}

export interface Terminal {
    id: string;
    workgroup_id: string | null;
    workgroup?: Workgroup | null;
    code: string;
    name: string;
    serial: string | null;
    kind: string;
    protocol: string;
    host: string | null;
    port: number | null;
    has_secret: boolean;
    active: boolean;
    seen_at: string | null;
    synced_at: string | null;
    stamp: string | null;
}

export interface Enrollment {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    terminal_id: string;
    uid: string;
    privilege: Choice;
    starts: string;
    ends: string | null;
    current: boolean;
}

export interface Timelog {
    id: string;
    terminal_id: string;
    terminal?: Terminal | null;
    uid: string;
    time: string;
    state: { value: number; label: string };
    mode: { value: number; label: string };
    source: string;
    employee_id: string | null;
    employee?: Employee | null;
    voided_at: string | null;
    reason: string | null;
    voider?: { id: string; name: string } | null;
}

export interface Sync {
    id: string;
    terminal_id: string;
    terminal?: Terminal | null;
    trigger: Choice;
    status: string;
    started_at: string;
    finished_at: string | null;
    received: number;
    accepted: number;
    duplicates: number;
    rejected: number;
    reference: string | null;
    earliest: string | null;
    latest: string | null;
    error: string | null;
}

export interface Holiday {
    id: string;
    date: string;
    name: string;
    type: Choice;
    reference: string | null;
    declared_at: string;
    national: boolean;
}

export interface Suspension {
    id: string;
    workgroup_id: string | null;
    workgroup?: Workgroup | null;
    date: string;
    starts: string | null;
    ends: string | null;
    reason: string;
    reference: string | null;
    declared_at: string;
    user?: { id: string; name: string } | null;
}

export interface Exemption {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    date: string;
    until: string;
    type: Choice;
    starts: string | null;
    ends: string | null;
    reference: string | null;
    remarks: string | null;
    approved_at: string;
    spans_days: boolean;
}

export interface Overtime {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    date: string;
    starts: string;
    ends: string;
    purpose: string;
    mode: Choice;
    reference: string | null;
    overnight: boolean;
}

export interface Punch {
    id: string;
    slot: number;
    kind: Choice;
    expected_at: string | null;
    actual_at: string | null;
    deviation: number | null;
    timelog_id: string | null;
}

export interface Workday {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    date: string;
    status: Choice;
    premium: Choice | null;
    worked: number;
    credited: number;
    tardy: number;
    undertime: number;
    excess: number;
    night: number;
    night_excess: number;
    punches?: Punch[];
    exemption?: { id: string; type: Choice; reference: string | null } | null;
    shift_name: string | null;
    computed_at: string;
}

export interface Ledger {
    id: string;
    employee_id: string;
    employee?: Employee | null;
    month: string;
    locked_at: string | null;
    starts: string;
    ends: string;
    scope: Choice;
    revision: number;
    cadence_id: string | null;
    cadence?: Cadence | null;
    locked_by: string | null;
    unlocked_at: string | null;
    unlocked_by: string | null;
    identity: LedgerIdentity;
    policy: LedgerPolicy;
    signers: LedgerSigner[];
    attestations?: Attestation[];
    renditions?: Rendition[];
}

export interface Cadence {
    id: string;
    name: string;
    kind: Choice;
    rules: { starts?: number[] };
    anchor: string | null;
    preferred: boolean;
    retired_at: string | null;
}

export type AttestationRole = 'employee' | 'supervisor' | 'head' | 'timekeeper';

export interface LedgerPolicy {
    id?: string;
    employee_id?: string | null;
    workgroup_id?: string | null;
    template: 'form48' | 'plain' | null;
    roles: AttestationRole[] | null;
    supervisor: 'operative' | 'substantive' | null;
    head_kind: string | null;
}

export interface LedgerSigner {
    role: AttestationRole;
    user_ids: string[];
    names?: string[];
}

export interface Attestation {
    id: string;
    role: AttestationRole;
    sequence: number;
    user_id: string;
    name: string;
    at: string;
    withdrawn_at?: string | null;
    can_withdraw?: boolean;
}

export interface LedgerDocument {
    id: string;
    name: string;
    mime: string;
    bytes: number;
    algorithm: string;
    digest: string;
}

export interface Rendition {
    id: string;
    revision: number;
    template: string;
    status: 'unstored' | 'pending' | 'ready' | 'failed';
    token: string;
    verification_url: string;
    requested_at: string | null;
    generated_at: string | null;
    failed_at: string | null;
    superseded_at: string | null;
    document?: LedgerDocument | null;
}

export interface LedgerIdentity {
    agency?: { id: string; name: string; code: string };
    employee?: { id: string; name: string; number: string; position: string | null };
    workgroup?: { id: string; name: string; code: string } | null;
}

export interface LedgerSnapshot extends LedgerIdentity {
    ledger: { id: string; starts: string; ends: string; scope: string; revision: number; locked_at: string };
    workdays: Workday[];
    totals: Omit<LedgerView, 'workdays'> & {
        nightExcess?: number;
        tardyOccurrences?: number;
        undertimeOccurrences?: number;
    };
    policy: LedgerPolicy;
    signers: LedgerSigner[];
    attestations: Attestation[];
    rendition?: { id: string; revision: number; token: string; completed_at: string; archiving: boolean };
}

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

export interface Deployment {
    id: string;
    workgroup?: Workgroup | null;
    parent_id: string | null;
    starts: string;
    ends: string | null;
}
export interface ShiftSlot {
    in: string;
    out: string;
    grace?: number;
    window: [number, number];
}

export interface Shift {
    id: string;
    name: string;
    slots: ShiftSlot[];
    required: number;
    flex: number;
    remote: boolean;
    trust: boolean;
    color: number;
    kind: 'working' | 'off' | 'remote';
    origin_id: string | null;
    origin?: Shift | null;
}

export interface Turn {
    id: string;
    position: number;
    shift_id: string;
    shift?: Shift;
}

export interface Schedule {
    id: string;
    name: string;
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
    anchor: string;
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

export type Appearance = 'light' | 'dark' | 'system';

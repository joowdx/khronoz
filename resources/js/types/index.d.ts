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
 * Matches UnitResource. The tree is composed client-side from a flat list
 * keyed by `parent_id` (see lib/units.ts), so there is no nested `children`
 * here — the resource does not send one.
 *
 * `head` is `whenLoaded`: absent means the controller did not ask for it,
 * `null` means the unit has no head. `people_count` is a `withCount`
 * aggregate present only on the units index, so it lives in that page's own
 * row type rather than here — the same split `AgencyRow` uses for
 * `users_count`.
 */
export interface Unit {
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
 * Matches DeploymentResource. One placement of one employee in one unit over
 * a date range; `ends: null` is the open, current one. Carries no `employee`:
 * it only ever appears nested under the employee it belongs to.
 */
export interface Deployment {
    id: string;
    unit?: Unit | null;
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

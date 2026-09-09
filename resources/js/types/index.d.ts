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

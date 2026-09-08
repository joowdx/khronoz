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

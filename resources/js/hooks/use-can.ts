import { usePage } from '@inertiajs/react';
import type { Permission, SharedProps } from '@/types';

export const implied: Partial<Record<Permission, Permission>> = {
    'organization.manage': 'organization.view',
    'scheduling.manage': 'scheduling.view',
    'calendar.manage': 'calendar.view',
    'terminals.manage': 'terminals.view',
    'ledgers.manage': 'ledgers.view',
    'ledgers.attest': 'ledgers.view',
};

export function useCan(): (permission: Permission) => boolean {
    const { auth } = usePage<SharedProps>().props;
    return (permission) => {
        const user = auth?.user;
        if (!user) return false;
        if (user.platform) return true;
        return user.permissions.some((held) => held === permission || implied[held] === permission);
    };
}

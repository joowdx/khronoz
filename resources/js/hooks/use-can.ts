import { usePage } from '@inertiajs/react';
import type { Permission, SharedProps } from '@/types';

const implied: Partial<Record<Permission, Permission>> = {
    'organization.manage': 'organization.view',
    'scheduling.manage': 'scheduling.view',
    'calendar.manage': 'calendar.view',
    'terminals.manage': 'terminals.view',
    'ledgers.manage': 'ledgers.view',
};

export function useCan(): (permission: Permission) => boolean {
    const { auth } = usePage<SharedProps>().props;
    return (permission) => {
        // `auth` is only shared from Task 6 on; tolerate it being absent
        // at runtime even though SharedProps declares it as required.
        const user = auth?.user;
        if (!user) return false;
        if (user.platform) return true;
        return user.permissions.some((held) => held === permission || implied[held] === permission);
    };
}

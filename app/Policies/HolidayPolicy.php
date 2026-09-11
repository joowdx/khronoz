<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Holiday;
use App\Models\User;

/**
 * Gate::before (AppServiceProvider::configureAuthorization) answers true for a
 * platform user before any of these run — which is exactly how a national
 * holiday gets maintained, since it belongs to the platform agency and the
 * rules below deliberately refuse it to everybody else.
 */
class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewCalendar);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageCalendar);
    }

    /**
     * An agency may edit its own holidays and **not** the national ones.
     *
     * `holidays` is the one table read under AgencyOrPlatformScope: a tenant
     * sees its own rows and the platform agency's, because a national holiday
     * applies to everyone (07-constraints.md). That makes the list mixed, and
     * a row an agency cannot change has to be refused here rather than merely
     * hidden in the interface — the index is not the only way to reach this.
     */
    public function update(User $user, Holiday $holiday): bool
    {
        return $user->allows(Permission::ManageCalendar) && ! $holiday->national();
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->allows(Permission::ManageCalendar) && ! $holiday->national();
    }
}

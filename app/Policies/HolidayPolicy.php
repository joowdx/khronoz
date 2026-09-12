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

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->allows(Permission::ManageCalendar) && ! $holiday->national();
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->allows(Permission::ManageCalendar) && ! $holiday->national();
    }
}

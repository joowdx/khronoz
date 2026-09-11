<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Roster;
use App\Models\User;

/**
 * Scheduling is one permission area: shifts, schedules, teams and rosters are
 * maintained together by whoever owns the timetable, so all four policies read
 * the same pair (docs/design/02-access.md).
 *
 * There is deliberately no platform-row refusal of the kind HolidayPolicy
 * carries. `holidays` is read under AgencyOrPlatformScope, so a tenant's list
 * genuinely mixes rows it may not edit; `rosters` is read under the plain
 * AgencyScope, so a platform-owned row never reaches an agency's query at all.
 * The one screen that does see them — the defaults list — reaches them with
 * withoutGlobalScopes and offers copy and refresh, never edit.
 *
 * Gate::before (AppServiceProvider::configureAuthorization) answers true for a
 * platform user before any of this runs, which is how the platform's own
 * defaults are maintained.
 */
class RosterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->allows(Permission::ViewScheduling);
    }

    public function view(User $user, Roster $roster): bool
    {
        return $user->allows(Permission::ViewScheduling);
    }

    public function create(User $user): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }

    public function update(User $user, Roster $roster): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }

    public function delete(User $user, Roster $roster): bool
    {
        return $user->allows(Permission::ManageScheduling);
    }
}

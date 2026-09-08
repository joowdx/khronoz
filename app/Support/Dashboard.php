<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One predicate behind /horizon and /telescope.
 *
 * Both packages register their own gate, and each gate delegates here so the
 * answer to "who may look at the machine room" is written down exactly once.
 */
class Dashboard
{
    /**
     * Determine whether the given user may view the operational dashboards.
     *
     * TODO: replace the allow list with the platform-agency check from
     * docs/design/02-access.md rule 3 once `Agency` and `User.role` exist.
     */
    public static function allows(?Authenticatable $user): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return $user !== null && in_array(
            $user->email,
            config('dashboard.emails'),
            strict: true,
        );
    }
}

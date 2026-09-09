<?php

namespace App\Support;

use App\Models\User;
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
     * Anyone may look locally; outside local, only a superuser of the
     * platform agency may (docs/design/02-access.md rule 3).
     */
    public static function allows(?Authenticatable $user): bool
    {
        return app()->environment('local') || ($user instanceof User && $user->isPlatform());
    }
}

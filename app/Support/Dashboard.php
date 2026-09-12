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
    public static function allows(?Authenticatable $user): bool
    {
        return app()->environment('local') || ($user instanceof User && $user->isPlatform());
    }
}

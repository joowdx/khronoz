<?php

namespace App\Listeners;

use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EnsureMigrationsRunAsOwner
{
    /**
     * A habitual `php artisan migrate` would run as the app role and fail half-way.
     *
     * `Migrator::directConnectionName()` appends `::direct` to the default
     * connection when a direct connection is configured, and `::read` /
     * `::write` are possible under a read/write split; none of those change
     * which role is actually connecting. So the suffix is stripped before
     * comparing against `owner`.
     */
    public function handle(MigrationsStarted $event): void
    {
        $connection = preg_replace('/::(?:direct|read|write)$/', '', DB::getDefaultConnection());

        if ($connection !== 'owner') {
            throw new RuntimeException('Migrations must run on the owner connection: use `composer migrate` (php artisan migrate --database=owner).');
        }
    }
}

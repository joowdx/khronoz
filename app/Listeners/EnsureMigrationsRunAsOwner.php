<?php

namespace App\Listeners;

use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EnsureMigrationsRunAsOwner
{
    public function handle(MigrationsStarted $event): void
    {
        $connection = preg_replace('/::(?:direct|read|write)$/', '', DB::getDefaultConnection());

        if ($connection !== 'owner') {
            throw new RuntimeException('Migrations must run on the owner connection: use `composer migrate` (php artisan migrate --database=owner).');
        }
    }
}

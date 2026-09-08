<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Scopes\NotPlatformScope;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    /**
     * Seed the platform row. firstOrCreate keys on `platform` itself, so
     * running this twice (a re-seed, a second test process) touches nothing
     * on the second run instead of tripping agencies_platform.
     */
    public function run(): void
    {
        Agency::withoutGlobalScope(NotPlatformScope::class)->firstOrCreate(
            ['platform' => true],
            ['code' => 'platform', 'name' => 'khronoz'],
        );
    }
}

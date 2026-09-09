<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Scopes\NotPlatformScope;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    /**
     * Seed the platform row, keyed on `platform` itself so running this
     * twice (a re-seed, a second test process) touches nothing on the
     * second run instead of tripping agencies_platform.
     *
     * forceFill, not firstOrCreate: firstOrCreate would mass-assign `platform`
     * through fill(), but `platform` is deliberately absent from Agency's
     * #[Fillable] (docs/design/02-access.md — it is a flag the row-level
     * privileges and trigger protect, not something a request should ever
     * set). That only works today because SeedCommand wraps every seeder's
     * run() in Model::unguarded(), so calling this seeder any other way
     * (a test invoking it directly, a future console command) would throw
     * MassAssignmentException under Model::shouldBeStrict(). forceFill makes
     * the seeder correct on its own, independent of that wrapper.
     */
    public function run(): void
    {
        $exists = Agency::withoutGlobalScope(NotPlatformScope::class)->where('platform', true)->exists();

        if ($exists) {
            return;
        }

        (new Agency)->forceFill([
            'platform' => true,
            'code' => 'platform',
            'name' => 'khronoz',
        ])->save();
    }
}

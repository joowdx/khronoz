<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Scopes\NotPlatformScope;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
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

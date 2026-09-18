<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlatformSeeder::class);

        // Product defaults seed here so platform fixture counts stay test-controlled.
        $this->call(DefaultsSeeder::class);

        // Guard the development superuser against repeated seeding.
        if (app()->environment('local', 'development', 'staging') && User::where('email', 'superuser@khronoz.test')->doesntExist()) {
            User::factory()->platform()->create([
                'name' => 'Superuser',
                'email' => 'superuser@khronoz.test',
            ]);
        }

        // The sample organization lives in DemoSeeder, which is explicit-only:
        // it purges and rebuilds the demo agency, so it must never run here.
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PlatformSeeder::class);

        // Dev-only convenience login: a superuser of the platform agency, so
        // local development starts with an account that can manage every
        // agency (docs/design/02-access.md rule 3). Guarded so re-running the
        // seeder against an already-seeded database does not collide with
        // users_email.
        if (app()->environment('local') && User::where('email', 'superuser@khronoz.test')->doesntExist()) {
            User::factory()->platform()->create([
                'name' => 'Superuser',
                'email' => 'superuser@khronoz.test',
            ]);
        }
    }
}

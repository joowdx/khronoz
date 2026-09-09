<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
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

        // Dev-only sample organization: one agency with a two-level unit
        // tree and two deployed employees, so local development has an org
        // chart to browse without seeding it by hand. Guarded the same way
        // as the superuser block above, keyed on the agency's own code.
        // agency_id is set explicitly throughout, never left to a tenant
        // that is never set here (factories.md).
        if (app()->environment('local') && Agency::where('code', 'demo')->doesntExist()) {
            $agency = Agency::factory()->create(['code' => 'demo', 'name' => 'Demo Agency']);

            $department = Unit::factory()->create([
                'agency_id' => $agency->id,
                'kind' => 'department',
                'code' => 'OED',
                'name' => 'Office of the Executive Director',
            ]);

            $head = Employee::factory()->create([
                'agency_id' => $agency->id,
                'position' => 'Division Chief',
            ]);

            $division = Unit::factory()->under($department)->create([
                'kind' => 'division',
                'code' => 'ADMIN',
                'name' => 'Administrative Division',
                'head_id' => $head->id,
            ]);

            Deployment::factory()->open()->create([
                'agency_id' => $agency->id,
                'employee_id' => $head->id,
                'unit_id' => $division->id,
            ]);

            $staff = Employee::factory()->create(['agency_id' => $agency->id]);

            Deployment::factory()->open()->create([
                'agency_id' => $agency->id,
                'employee_id' => $staff->id,
                'unit_id' => $division->id,
            ]);
        }
    }
}

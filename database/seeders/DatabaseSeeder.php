<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\User;
use App\Models\Workgroup;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlatformSeeder::class);

        // Product defaults seed here so platform fixture counts stay test-controlled.
        $this->call(DefaultsSeeder::class);

        // Guard the development superuser against repeated seeding.
        if (app()->environment('local') && User::where('email', 'superuser@khronoz.test')->doesntExist()) {
            User::factory()->platform()->create([
                'name' => 'Superuser',
                'email' => 'superuser@khronoz.test',
            ]);
        }

        // The sample organization sets agency IDs explicitly because no tenant is active.
        if (app()->environment('local') && Agency::where('code', 'demo')->doesntExist()) {
            $agency = Agency::factory()->create(['code' => 'demo', 'name' => 'Demo Agency']);

            $department = Workgroup::factory()->create([
                'agency_id' => $agency->id,
                'kind' => 'department',
                'code' => 'OED',
                'name' => 'Office of the Executive Director',
            ]);

            $head = Employee::factory()->create([
                'agency_id' => $agency->id,
                'position' => 'Division Chief',
            ]);

            $division = Workgroup::factory()->under($department)->create([
                'kind' => 'division',
                'code' => 'ADMIN',
                'name' => 'Administrative Division',
                'head_id' => $head->id,
            ]);

            Deployment::factory()->open()->create([
                'agency_id' => $agency->id,
                'employee_id' => $head->id,
                'workgroup_id' => $division->id,
            ]);

            $staff = Employee::factory()->create(['agency_id' => $agency->id]);

            $placement = Deployment::factory()->open()->create([
                'agency_id' => $agency->id,
                'employee_id' => $staff->id,
                'workgroup_id' => $division->id,
            ]);

            // This reassignment remains within the employee's open substantive placement.
            Deployment::factory()->under($placement)->create([
                'workgroup_id' => $department->id,
                'starts' => today()->subMonth(),
                'ends' => today()->addMonths(2),
            ]);
        }
    }
}

<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Unit;
use Tests\TestCase;

class EmployeeDeploymentControllerTest extends TestCase
{
    public function test_moves_the_employee_closing_the_current_deployment_and_opening_the_new_one(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $unitA = Unit::factory()->create(['agency_id' => $agency->id]);
        $unitB = Unit::factory()->create(['agency_id' => $agency->id, 'name' => 'Records']);
        $current = Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unitA->id,
            'starts' => '2024-01-01',
            'ends' => null,
        ]);

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'unit_id' => $unitB->id,
            'starts' => '2026-03-01',
        ])->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('success', "{$employee->name} moved to Records.");

        $this->assertSame('2026-02-28', $current->fresh()->ends->toDateString());
        $new = Deployment::query()->where('employee_id', $employee->id)->where('unit_id', $unitB->id)->firstOrFail();
        $this->assertSame('2026-03-01', $new->starts->toDateString());
        $this->assertNull($new->ends);
    }

    /** R17, the ONLY place the hire-window gap is checked (docs/design/07-constraints.md:118-121). */
    public function test_refuses_a_start_date_before_the_employees_hire_date(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2024-01-01']);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'unit_id' => $unit->id,
            'starts' => '2023-01-01',
        ])->assertSessionHasErrors('starts');

        $this->assertDatabaseMissing('deployments', ['employee_id' => $employee->id]);
    }

    public function test_refuses_a_start_date_after_the_employees_separation_date(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01', 'separated_at' => '2024-06-30']);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'unit_id' => $unit->id,
            'starts' => '2024-07-01',
        ])->assertSessionHasErrors('starts');

        $this->assertDatabaseMissing('deployments', ['employee_id' => $employee->id]);
    }

    public function test_refuses_a_unit_from_another_agency(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $foreignUnit = Unit::factory()->create(); // a different agency
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'unit_id' => $foreignUnit->id,
            'starts' => '2026-01-01',
        ])->assertSessionHasErrors('unit_id');
    }

    public function test_view_only_is_forbidden(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'unit_id' => $unit->id,
            'starts' => '2026-01-01',
        ])->assertForbidden();
    }

    /** Same cross-tenant binding protection as EmployeeControllerTest's other routes, exercised through the deployment endpoint. */
    public function test_moving_an_employee_of_another_agency_is_not_found(): void
    {
        $stranger = Employee::factory()->create(['hired_at' => '2020-01-01']);
        $agency = Agency::factory()->create();
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $stranger), [
            'unit_id' => $unit->id,
            'starts' => '2026-01-01',
        ])->assertNotFound();
    }
}

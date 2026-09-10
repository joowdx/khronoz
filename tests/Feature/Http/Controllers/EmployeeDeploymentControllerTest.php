<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\EndEmployeeDeploymentRequest;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Unit;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeDeploymentControllerTest extends TestCase
{
    public function test_moves_the_employee_closing_the_current_deployment_and_opening_the_new_one(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
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

    public function test_refuses_a_unit_from_another_agency(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $foreignUnit = Unit::factory()->create(); // a different agency
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'unit_id' => $foreignUnit->id,
            'starts' => '2026-01-01',
        ])->assertSessionHasErrors('unit_id');
    }

    /**
     * The exclusion constraint, not this test, decides the overlap — see
     * MoveEmployee's docblock and MoveEmployeeTest::test_refuses_an_overlap
     * for why a *closed*, historical deployment lying in the new range is
     * the only shape that reaches deployments_no_overlap through this
     * action. This test proves the controller translates that constraint's
     * 23P01 refusal into a normal `starts` validation error instead of
     * letting it bubble up as an uncaught 500.
     */
    public function test_refuses_a_move_that_overlaps_an_existing_deployment(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $unitA = Unit::factory()->create(['agency_id' => $agency->id]);
        $unitB = Unit::factory()->create(['agency_id' => $agency->id]);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unitA->id,
            'starts' => '2025-06-01',
            'ends' => '2025-09-01',
        ]);

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'unit_id' => $unitB->id,
            'starts' => '2025-07-01',
        ])->assertSessionHasErrors(['starts' => 'Overlaps an existing deployment.']);

        // Neither the failed insert nor the historical row it collided with left a trace of the attempt.
        $this->assertDatabaseMissing('deployments', ['unit_id' => $unitB->id]);
        $this->assertDatabaseHas('deployments', ['employee_id' => $employee->id, 'starts' => '2025-06-01', 'ends' => '2025-09-01']);
    }

    /**
     * A move dated on or before the open deployment's own start.
     * MoveEmployee closes that row at `starts - 1`, which leaves
     * `ends < starts` and `deployments_dates_ordered` (a CHECK, 23514)
     * refuses the UPDATE. That is the refusal a real user hits: the sheet
     * used to offer every date back to the hire date, so for someone hired in
     * 2019 whose placement began in 2026 a seven-year window was fatal. The
     * controller translates it now, and the sheet's `min` no longer offers it.
     *
     * MEASURED: with an open deployment present this is the ONLY refusal
     * reachable single-threaded — the exclusion constraint keeps every range
     * disjoint, so the open row always holds the maximum start and 23P01
     * cannot be reached before 23514 is.
     */
    public function test_refuses_a_move_dated_before_the_current_placement_began(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $unitA = Unit::factory()->create(['agency_id' => $agency->id]);
        $unitB = Unit::factory()->create(['agency_id' => $agency->id]);
        $current = Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unitA->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'unit_id' => $unitB->id,
            'starts' => '2020-06-01',
        ])->assertSessionHasErrors(['starts' => 'Before the current placement began.']);

        // The whole move rolled back: no new row, and the open one is still open.
        $this->assertDatabaseMissing('deployments', ['unit_id' => $unitB->id]);
        $this->assertNull($current->fresh()->ends);
    }

    public function test_view_only_is_forbidden(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
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
        $stranger = Employee::factory()->create([]);
        $agency = Agency::factory()->create();
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $stranger), [
            'unit_id' => $unit->id,
            'starts' => '2026-01-01',
        ])->assertNotFound();
    }

    /** A close includes its last day; a rehire opens a new range without changing that history. */
    public function test_ends_a_placement_and_rehires_after_a_gap(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2024-01-01']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $employee = $placement->employee;

        $this->patch(route('employees.deployments.update', $employee), ['ends' => '2025-06-30'])
            ->assertRedirect(route('employees.show', $employee))->assertSessionHas('success');

        $this->assertSame('2025-06-30', $placement->fresh()->ends->toDateString());
        $this->assertNull($employee->fresh()->currentDeployment);

        $this->post(route('employees.deployments.store', $employee), [
            'unit_id' => $placement->unit_id, 'starts' => '2026-01-01',
        ])->assertRedirect(route('employees.show', $employee))->assertSessionHas('success');

        $this->assertSame('2025-06-30', $placement->fresh()->ends->toDateString());
        $this->assertSame(2, $employee->deployments()->count());
        $this->assertSame('2026-01-01', $employee->fresh()->currentDeployment->starts->toDateString());
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidEndDates(): array
    {
        return ['missing' => [null], 'malformed' => ['not-a-date'], 'before start' => ['2023-12-31']];
    }

    #[DataProvider('invalidEndDates')]
    public function test_end_placement_rejects_invalid_dates(mixed $ends): void
    {
        $placement = Deployment::factory()->create(['starts' => '2024-01-01']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);

        $response = $this->patch(route('employees.deployments.update', $placement->employee_id), ['ends' => $ends]);
        $response->assertSessionHasErrors($ends === '2023-12-31'
            ? ['ends' => 'Before the current placement began.'] : ['ends']);
        $this->assertNull($placement->fresh()->ends);
    }

    public function test_end_placement_accepts_the_start_date_itself(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2024-01-01']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);

        $this->patch(route('employees.deployments.update', $placement->employee_id), ['ends' => '2024-01-01'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('2024-01-01', $placement->fresh()->ends->toDateString());
    }

    public function test_end_placement_does_not_redate_closed_history(): void
    {
        $placement = Deployment::factory()->closed()->create(['starts' => '2024-01-01', 'ends' => '2024-12-31']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);

        $this->patch(route('employees.deployments.update', $placement->employee_id), ['ends' => '2025-06-30'])
            ->assertRedirect(route('employees.show', $placement->employee_id))
            ->assertSessionHas('error', 'No open placement to end.');

        $this->assertSame('2024-12-31', $placement->fresh()->ends->toDateString());
    }

    public function test_end_placement_without_any_history_returns_an_error_flash(): void
    {
        $employee = Employee::factory()->create();
        $this->actingAsAgency(Agency::findOrFail($employee->agency_id), Permission::ManageOrganization);

        $this->patch(route('employees.deployments.update', $employee), ['ends' => '2025-06-30'])
            ->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('error', 'No open placement to end.');

        $this->assertSame(0, $employee->deployments()->count());
    }

    public function test_end_placement_requires_manage_permission(): void
    {
        $placement = Deployment::factory()->create();
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ViewOrganization);

        $this->patch(route('employees.deployments.update', $placement->employee_id), ['ends' => '2026-09-10'])
            ->assertForbidden();
        $this->assertNull($placement->fresh()->ends);
    }

    public function test_end_placement_of_another_agencys_employee_is_not_found(): void
    {
        $placement = Deployment::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->patch(route('employees.deployments.update', $placement->employee_id), ['ends' => '2026-09-10'])
            ->assertNotFound();
    }

    /** Bypass only request validation to isolate the database-refusal translation and its savepoint. */
    public function test_end_placement_translates_a_database_refusal_independently_of_validation(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2024-01-01']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);
        $this->app->bind(EndEmployeeDeploymentRequest::class, fn () => new class extends EndEmployeeDeploymentRequest
        {
            public function after(): array
            {
                return [];
            }
        });

        $this->patch(route('employees.deployments.update', $placement->employee_id), ['ends' => '2023-12-31'])
            ->assertSessionHasErrors(['ends' => 'Before the current placement began.']);

        $this->assertNull($placement->fresh()->ends);
    }
}

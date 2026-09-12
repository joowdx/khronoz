<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\EndEmployeeDeploymentRequest;
use App\Jobs\FanOutRecompute;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeDeploymentControllerTest extends TestCase
{
    public function test_moves_the_employee_closing_the_current_deployment_and_opening_the_new_one(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroupA = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $workgroupB = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Records']);
        $current = Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupA->id,
            'starts' => '2024-01-01',
            'ends' => null,
        ]);

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $workgroupB->id,
            'starts' => '2026-03-01',
        ])->assertRedirect(route('employees.show', $employee))
            ->assertSessionHas('success', "{$employee->name} moved to Records.");

        $this->assertSame('2026-02-28', $current->fresh()->ends->toDateString());
        $new = Deployment::query()->where('employee_id', $employee->id)->where('workgroup_id', $workgroupB->id)->firstOrFail();
        $this->assertSame('2026-03-01', $new->starts->toDateString());
        $this->assertNull($new->ends);
    }

    public function test_refuses_a_workgroup_from_another_agency(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $foreignWorkgroup = Workgroup::factory()->create(); // a different agency
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $foreignWorkgroup->id,
            'starts' => '2026-01-01',
        ])->assertSessionHasErrors('workgroup_id');
    }

    public function test_refuses_a_move_that_overlaps_an_existing_deployment(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroupA = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $workgroupB = Workgroup::factory()->create(['agency_id' => $agency->id]);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupA->id,
            'starts' => '2025-06-01',
            'ends' => '2025-09-01',
        ]);

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $workgroupB->id,
            'starts' => '2025-07-01',
        ])->assertSessionHasErrors(['starts' => 'Overlaps an existing deployment.']);

        // Neither the failed insert nor the historical row it collided with left a trace of the attempt.
        $this->assertDatabaseMissing('deployments', ['workgroup_id' => $workgroupB->id]);
        $this->assertDatabaseHas('deployments', ['employee_id' => $employee->id, 'starts' => '2025-06-01', 'ends' => '2025-09-01']);
    }

    public function test_refuses_a_move_dated_before_the_current_placement_began(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroupA = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $workgroupB = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $current = Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroupA->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $workgroupB->id,
            'starts' => '2020-06-01',
        ])->assertSessionHasErrors(['starts' => 'Before the current placement began.']);

        // The whole move rolled back: no new row, and the open one is still open.
        $this->assertDatabaseMissing('deployments', ['workgroup_id' => $workgroupB->id]);
        $this->assertNull($current->fresh()->ends);
    }

    public function test_view_only_is_forbidden(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $workgroup->id,
            'starts' => '2026-01-01',
        ])->assertForbidden();
    }

    public function test_moving_an_employee_of_another_agency_is_not_found(): void
    {
        $stranger = Employee::factory()->create([]);
        $agency = Agency::factory()->create();
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $stranger), [
            'workgroup_id' => $workgroup->id,
            'starts' => '2026-01-01',
        ])->assertNotFound();
    }

    public function test_ends_a_placement_and_rehires_after_a_gap(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2024-01-01']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);
        $this->withTenant(Agency::findOrFail($placement->agency_id));
        $employee = $placement->employee;

        $this->patch(route('employees.deployments.update', $employee), [
            'deployment' => $placement->id, 'expects' => null, 'ends' => '2025-06-30',
        ])->assertRedirect(route('employees.show', $employee))->assertSessionHas('success');

        $this->assertSame('2025-06-30', $placement->fresh()->ends->toDateString());
        $this->assertNull($employee->fresh()->currentDeployment);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $placement->workgroup_id, 'starts' => '2026-01-01',
        ])->assertRedirect(route('employees.show', $employee))->assertSessionHas('success');

        $this->assertSame('2025-06-30', $placement->fresh()->ends->toDateString());
        $this->assertSame(2, $employee->deployments()->count());
        $this->assertSame('2026-01-01', $employee->fresh()->currentDeployment->starts->toDateString());
    }

    public function test_reassignment_opens_a_nested_row_and_leaves_the_placement_open(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 08:00:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        $employee = $placement->employee;
        $elsewhere = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Civil Security Unit']);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-03-01',
            'ends' => '2026-05-31',
            'reassignment' => true,
        ])->assertRedirect(route('employees.show', $employee))->assertSessionHas('success');

        $this->assertNull($placement->fresh()->ends, 'a reassignment must not close the placement');
        $this->assertDatabaseHas('deployments', [
            'employee_id' => $employee->id,
            'workgroup_id' => $elsewhere->id,
            'parent_id' => $placement->id,
            'starts' => '2026-03-01',
            'ends' => '2026-05-31',
        ]);
        $this->assertSame(2, $employee->deployments()->count());
    }

    public function test_the_same_payload_without_the_flag_closes_the_placement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 08:00:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        $employee = $placement->employee;
        $elsewhere = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-03-01',
        ])->assertRedirect(route('employees.show', $employee))->assertSessionHas('success');

        $this->assertSame('2026-02-28', $placement->fresh()->ends->toDateString());
        $this->assertNull($employee->fresh()->currentDeployment->parent_id);
    }

    public function test_reassignment_is_refused_when_there_is_no_open_placement(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $workgroup->id,
            'starts' => '2026-03-01',
            'reassignment' => true,
        ])->assertSessionHasErrors('reassignment');

        $this->assertSame(0, $employee->deployments()->count());
    }

    public function test_reassignment_cannot_start_before_its_placement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 08:00:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        $elsewhere = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('employees.deployments.store', $placement->employee), [
            'workgroup_id' => $elsewhere->id,
            'starts' => '2025-06-01',
            'reassignment' => true,
        ])->assertSessionHasErrors(['starts' => 'Before the current placement began.']);

        $this->assertSame(1, $placement->employee->deployments()->count());
    }

    public function test_reassignment_cannot_outlive_a_fixed_term_placement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 08:00:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => '2026-06-30']);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        $elsewhere = Workgroup::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('employees.deployments.store', $placement->employee), [
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-03-01',
            'ends' => '2026-07-31',
            'reassignment' => true,
        ])->assertSessionHasErrors('ends');

        $this->post(route('employees.deployments.store', $placement->employee), [
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-03-01',
            'reassignment' => true,
        ])->assertSessionHasErrors('ends');

        $this->assertSame(1, $placement->employee->deployments()->count());
    }

    public function test_a_transfer_may_record_a_fixed_term_placement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00'));
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $workgroup->id,
            'starts' => '2026-01-01',
            'ends' => '2026-12-31',
        ])->assertRedirect(route('employees.show', $employee))->assertSessionHas('success');

        $current = $employee->fresh()->currentDeployment;

        $this->assertNotNull($current, 'a fixed-term placement covering today is still the current one');
        $this->assertSame('2026-12-31', $current->ends->toDateString());
    }

    public function test_a_mistyped_deployment_is_deleted_and_recreated_on_the_same_dates(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        $employee = $placement->employee;
        $intended = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Records Section']);

        $this->delete(route('employees.deployments.destroy', [$employee, $placement]))
            ->assertRedirect(route('employees.show', $employee))->assertSessionHas('success');

        $this->assertDatabaseMissing('deployments', ['id' => $placement->id]);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $intended->id,
            'starts' => '2026-01-01',
        ])->assertSessionHas('success');

        $this->assertSame($intended->id, $employee->fresh()->currentDeployment->workgroup_id);
        $this->assertSame(1, $employee->deployments()->count());
    }

    public function test_a_placement_with_a_reassignment_cannot_be_deleted_first(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $reassignment = Deployment::factory()->under($placement)->create(['starts' => '2026-03-01', 'ends' => '2026-05-31']);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        $employee = $placement->employee;

        $this->delete(route('employees.deployments.destroy', [$employee, $placement]))
            ->assertSessionHasErrors('deployment');

        $this->assertDatabaseHas('deployments', ['id' => $placement->id]);

        // Innermost first works.
        $this->delete(route('employees.deployments.destroy', [$employee, $reassignment]))->assertSessionHas('success');
        $this->delete(route('employees.deployments.destroy', [$employee, $placement]))->assertSessionHas('success');

        $this->assertSame(0, $employee->deployments()->count());
    }

    public function test_a_deployment_of_another_employee_is_not_found(): void
    {
        $mine = Deployment::factory()->create();
        $agency = Agency::findOrFail($mine->agency_id);
        $theirs = Deployment::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->delete(route('employees.deployments.destroy', [$mine->employee_id, $theirs->id]))->assertNotFound();

        $this->assertDatabaseHas('deployments', ['id' => $theirs->id]);
    }

    public function test_deleting_a_deployment_requires_the_organization_permission(): void
    {
        $placement = Deployment::factory()->create();
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->delete(route('employees.deployments.destroy', [$placement->employee_id, $placement->id]))->assertForbidden();

        $this->assertDatabaseHas('deployments', ['id' => $placement->id]);
    }

    public function test_a_stale_end_request_cannot_close_the_replacement_placement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 08:00:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        $employee = $placement->employee;
        $elsewhere = Workgroup::factory()->create(['agency_id' => $agency->id]);

        // The sheet is rendered against the placement as it stands now.
        $stale = ['deployment' => $placement->id, 'expects' => null, 'ends' => '2026-03-31'];

        // Meanwhile, someone transfers the employee: the placement above is
        // closed and a replacement opens.
        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => $elsewhere->id,
            'starts' => '2026-02-01',
        ])->assertSessionHas('success');

        $replacement = $employee->fresh()->currentDeployment;
        $this->assertSame($elsewhere->id, $replacement->workgroup_id);

        $this->patch(route('employees.deployments.update', $employee), $stale)
            ->assertSessionHasErrors('ends');

        $this->assertSame('2026-01-31', $placement->fresh()->ends->toDateString());
        $this->assertNull($replacement->fresh()->ends, 'the replacement must not be touched');
    }

    public function test_a_second_end_request_does_not_overwrite_the_first(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        $employee = $placement->employee;

        $payload = ['deployment' => $placement->id, 'expects' => null, 'ends' => '2026-06-30'];

        $this->patch(route('employees.deployments.update', $employee), $payload)->assertSessionHas('success');
        $this->assertSame('2026-06-30', $placement->fresh()->ends->toDateString());

        // Replaying the same form, whose `expects` is still null while the row
        // now carries a date.
        $this->patch(route('employees.deployments.update', $employee), [...$payload, 'ends' => '2026-09-30'])
            ->assertSessionHasErrors('ends');

        $this->assertSame('2026-06-30', $placement->fresh()->ends->toDateString());
    }

    public function test_ending_a_placement_leaves_a_nested_reassignment_alone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 08:00:00'));
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $reassignment = Deployment::factory()->under($placement)->create(['starts' => '2026-03-01', 'ends' => '2026-05-31']);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        $employee = $placement->employee;

        // Closing before the reassignment ends would strand it.
        $this->patch(route('employees.deployments.update', $employee), [
            'deployment' => $placement->id, 'expects' => null, 'ends' => '2026-04-30',
        ])->assertSessionHasErrors('ends');

        // Closing after it is fine, and leaves the reassignment untouched.
        $this->patch(route('employees.deployments.update', $employee), [
            'deployment' => $placement->id, 'expects' => null, 'ends' => '2026-06-30',
        ])->assertSessionHas('success');

        $this->assertSame('2026-05-31', $reassignment->fresh()->ends->toDateString());
        $this->assertSame('2026-06-30', $placement->fresh()->ends->toDateString());
    }

    public function test_the_end_form_cannot_name_a_reassignment(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $reassignment = Deployment::factory()->under($placement)->create(['starts' => '2026-03-01', 'ends' => '2026-05-31']);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);

        $this->patch(route('employees.deployments.update', $placement->employee), [
            'deployment' => $reassignment->id, 'expects' => '2026-05-31', 'ends' => '2026-04-30',
        ])->assertSessionHasErrors('deployment');

        $this->assertSame('2026-05-31', $reassignment->fresh()->ends->toDateString());
    }

    public function test_a_change_landing_after_validation_reports_a_lost_race(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2024-01-01', 'ends' => null]);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);
        $this->app->bind(EndEmployeeDeploymentRequest::class, fn () => new class extends EndEmployeeDeploymentRequest
        {
            public function after(): array
            {
                return [];
            }
        });

        // `expects` names a value the row does not hold, standing in for a
        // change committed after validation passed.
        $this->patch(route('employees.deployments.update', $placement->employee_id), [
            'deployment' => $placement->id, 'expects' => '2026-01-01', 'ends' => '2026-06-30',
        ])->assertRedirect(route('employees.show', $placement->employee_id))->assertSessionHas('error');

        $this->assertNull($placement->fresh()->ends, 'the row must be untouched');
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

        $response = $this->patch(route('employees.deployments.update', $placement->employee_id), [
            'deployment' => $placement->id, 'expects' => null, 'ends' => $ends,
        ]);
        $response->assertSessionHasErrors($ends === '2023-12-31'
            ? ['ends' => 'Before the current placement began.'] : ['ends']);
        $this->assertNull($placement->fresh()->ends);
    }

    public function test_end_placement_accepts_the_start_date_itself(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2024-01-01']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);

        $this->patch(route('employees.deployments.update', $placement->employee_id), [
            'deployment' => $placement->id, 'expects' => null, 'ends' => '2024-01-01',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('2024-01-01', $placement->fresh()->ends->toDateString());
    }

    public function test_end_placement_does_not_redate_closed_history(): void
    {
        $placement = Deployment::factory()->closed()->create(['starts' => '2024-01-01', 'ends' => '2024-12-31']);
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ManageOrganization);

        $this->patch(route('employees.deployments.update', $placement->employee_id), [
            'deployment' => $placement->id, 'expects' => null, 'ends' => '2025-06-30',
        ])->assertSessionHasErrors('ends');

        $this->assertSame('2024-12-31', $placement->fresh()->ends->toDateString());
    }

    public function test_end_placement_without_any_history_returns_an_error_flash(): void
    {
        $employee = Employee::factory()->create();
        $this->actingAsAgency(Agency::findOrFail($employee->agency_id), Permission::ManageOrganization);

        $this->patch(route('employees.deployments.update', $employee), [
            'deployment' => (string) Str::ulid(), 'expects' => null, 'ends' => '2025-06-30',
        ])->assertSessionHasErrors('deployment');

        $this->assertSame(0, $employee->deployments()->count());
    }

    public function test_end_placement_requires_manage_permission(): void
    {
        $placement = Deployment::factory()->create();
        $this->actingAsAgency(Agency::findOrFail($placement->agency_id), Permission::ViewOrganization);

        $this->patch(route('employees.deployments.update', $placement->employee_id), [
            'deployment' => $placement->id, 'expects' => null, 'ends' => '2026-09-10',
        ])->assertForbidden();
        $this->assertNull($placement->fresh()->ends);
    }

    public function test_end_placement_of_another_agencys_employee_is_not_found(): void
    {
        $placement = Deployment::factory()->create();
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageOrganization);

        $this->patch(route('employees.deployments.update', $placement->employee_id), [
            'deployment' => $placement->id, 'expects' => null, 'ends' => '2026-09-10',
        ])->assertNotFound();
    }

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

        $this->patch(route('employees.deployments.update', $placement->employee_id), [
            'deployment' => $placement->id, 'expects' => null, 'ends' => '2023-12-31',
        ])->assertSessionHasErrors(['ends' => 'Before the current placement began.']);

        $this->assertNull($placement->fresh()->ends);
    }

    public function test_a_transfer_queues_a_recompute_from_the_day_it_starts(): void
    {
        $agency = Agency::factory()->create();
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => Workgroup::factory()->create(['agency_id' => $agency->id])->id,
            'starts' => '2024-01-01',
            'ends' => null,
        ]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        Queue::fake([FanOutRecompute::class]);

        $this->post(route('employees.deployments.store', $employee), [
            'workgroup_id' => Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Records'])->id,
            'starts' => '2026-03-01',
        ])->assertSessionHas('success');

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->employeeIds === [$employee->id]
                && $job->from === '2026-03-01'
                && $job->to === null,
        );
    }

    public function test_pulling_a_placements_end_in_queues_from_the_new_end(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2024-01-01', 'ends' => '2026-06-30']);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);

        Queue::fake([FanOutRecompute::class]);

        $this->patch(route('employees.deployments.update', $placement->employee), [
            'deployment' => $placement->id, 'expects' => '2026-06-30', 'ends' => '2026-03-31',
        ])->assertSessionHas('success');

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->employeeIds === [$placement->employee_id]
                && $job->from === '2026-03-31'
                && $job->to === null,
        );
    }

    public function test_pushing_a_placements_end_out_queues_from_the_old_end(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2024-01-01', 'ends' => '2026-03-31']);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);

        Queue::fake([FanOutRecompute::class]);

        $this->patch(route('employees.deployments.update', $placement->employee), [
            'deployment' => $placement->id, 'expects' => '2026-03-31', 'ends' => '2026-06-30',
        ])->assertSessionHas('success');

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->employeeIds === [$placement->employee_id]
                && $job->from === '2026-03-31'
                && $job->to === null,
        );
    }

    public function test_removing_a_deployment_queues_a_recompute_over_its_range(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => '2026-06-30']);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);

        Queue::fake([FanOutRecompute::class]);

        $this->delete(route('employees.deployments.destroy', [$placement->employee, $placement]))
            ->assertSessionHas('success');

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->employeeIds === [$placement->employee_id]
                && $job->from === '2026-01-01'
                && $job->to === '2026-06-30',
        );
    }

    public function test_a_placement_covering_a_locked_month_names_the_lock_and_not_a_reassignment(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        Ledger::factory()->locked()->create([
            'agency_id' => $agency->id,
            'employee_id' => $placement->employee_id,
            'month' => '2026-08-01',
        ]);

        $this->delete(route('employees.deployments.destroy', [$placement->employee, $placement]))
            ->assertSessionHasErrors(['deployment' => 'That placement covers a locked ledger. Unlock it first.']);

        $this->assertDatabaseHas('deployments', ['id' => $placement->id]);
    }
}

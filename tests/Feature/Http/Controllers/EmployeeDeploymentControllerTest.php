<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\EndEmployeeDeploymentRequest;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Workgroup;
use Carbon\CarbonImmutable;
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

    /**
     * The exclusion constraint, not this test, decides the overlap — see
     * TransferEmployee's docblock and TransferEmployeeTest::test_refuses_an_overlap
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

    /**
     * A move dated on or before the open deployment's own start.
     * TransferEmployee closes that row at `starts - 1`, which leaves
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

    /** Same cross-tenant binding protection as EmployeeControllerTest's other routes, exercised through the deployment endpoint. */
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
            'workgroup_id' => $placement->workgroup_id, 'starts' => '2026-01-01',
        ])->assertRedirect(route('employees.show', $employee))->assertSessionHas('success');

        $this->assertSame('2025-06-30', $placement->fresh()->ends->toDateString());
        $this->assertSame(2, $employee->deployments()->count());
        $this->assertSame('2026-01-01', $employee->fresh()->currentDeployment->starts->toDateString());
    }

    /**
     * The `reassignment` flag is the whole discriminator (decision 35), and
     * this is the property that distinguishes the two verbs: the substantive
     * placement stays open, because the plantilla item never left.
     */
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

    /**
     * The same payload without the flag is a transfer, and closes the
     * placement the day before. Asserted against the reassignment test above
     * so the flag is the only difference between them.
     */
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

    /**
     * No open placement means no parent to nest under, and nothing in the
     * schema can refuse it — the row would be written as a valid substantive
     * placement, so a reassignment would silently become a transfer. The
     * request catches it before ReassignEmployee has to raise.
     */
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

    /**
     * A reassignment cannot begin before the placement it departs from.
     *
     * The message is asserted, not just the field, and that is deliberate:
     * deployments_nested refuses this too, and the controller translates its
     * P0001 onto the same `starts` key — so a bare assertSessionHasErrors
     * passes whichever layer answered and would not notice the request check
     * disappearing (MEASURED: removing it left this test green). Validation
     * is meant to win here, before any write is attempted; the trigger is the
     * backstop for a concurrent change, exactly as
     * EndEmployeeDeploymentRequest documents for its own date check.
     */
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

    /**
     * A reassignment cannot outlive a fixed-term placement, and an
     * open-ended one always would — a null upper bound is unbounded, and a
     * closed range cannot contain it.
     */
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

    /**
     * A transfer may record a fixed term. The placement is then current
     * today while already carrying an `ends`, which is exactly the case
     * Employee::currentDeployment's date predicate exists for — "the open
     * row" would have reported no workgroup for this person.
     */
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

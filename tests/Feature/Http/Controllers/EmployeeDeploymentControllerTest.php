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

    /**
     * Correction is a delete and re-create (decision 35). The row was never
     * true, so the point of this test is that it is *gone* — not closed, not
     * soft-deleted — and that the corrected row can then occupy the same
     * dates, which a soft delete would have made impossible: the tombstone
     * would still occupy the timeline deployments_no_overlap indexes and
     * refuse its own replacement with 23P01.
     */
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

    /**
     * The delete side of the paired self-FK, which is written RESTRICT
     * explicitly for this: a placement with a reassignment nested under it
     * cannot go first, or the reassignment would be orphaned. Surfaced as a
     * message naming what to remove, not a 500.
     */
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

    /**
     * ->scopeBindings() on the route: another employee's deployment is not a
     * row this endpoint can reach, even for a user who may update both
     * employees. Without the scoping, {deployment} would resolve globally and
     * the only protection would be the policy on the wrong employee.
     */
    public function test_a_deployment_of_another_employee_is_not_found(): void
    {
        $mine = Deployment::factory()->create();
        $agency = Agency::findOrFail($mine->agency_id);
        $theirs = Deployment::factory()->create(['agency_id' => $agency->id]);
        $this->actingAsAgency($agency, Permission::ManageOrganization);

        $this->delete(route('employees.deployments.destroy', [$mine->employee_id, $theirs->id]))->assertNotFound();

        $this->assertDatabaseHas('deployments', ['id' => $theirs->id]);
    }

    /** Deleting a deployment is a change to that employee, so it needs the same ability as every other write here. */
    public function test_deleting_a_deployment_requires_the_organization_permission(): void
    {
        $placement = Deployment::factory()->create();
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ViewOrganization);

        $this->delete(route('employees.deployments.destroy', [$placement->employee_id, $placement->id]))->assertForbidden();

        $this->assertDatabaseHas('deployments', ['id' => $placement->id]);
    }

    /**
     * The stale-close hole the 2026-09-10 adversarial review found, and the
     * reason it is a security defect rather than a data-quality one: decision
     * 30 makes deployment ranges access control, so closing the wrong row
     * changes who can see that employee's records.
     *
     * The sequence is a clerk opening the end-placement sheet, someone else
     * transferring the employee, and the first clerk then submitting. The old
     * predicate named only the employee, so the submission closed whatever
     * was open by then — the *replacement* placement, in a workgroup the
     * first clerk never saw and never chose to end.
     */
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

        // The stale submission now lands. It must affect nothing.
        // The request layer now answers first: `expects` no longer matches the
        // row's `ends`, so this is a field error telling the clerk to reload,
        // rather than the generic flash it used to be. The controller's
        // predicate remains behind it for a change that lands after validation.
        $this->patch(route('employees.deployments.update', $employee), $stale)
            ->assertSessionHasErrors('ends');

        $this->assertSame('2026-01-31', $placement->fresh()->ends->toDateString());
        $this->assertNull($replacement->fresh()->ends, 'the replacement must not be touched');
    }

    /**
     * The same guard against a concurrent *end* of the very row this form
     * names: the id matches, but `ends` no longer does, so the second write
     * is refused instead of silently replacing the first clerk's date.
     */
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

    /**
     * Ending a placement must not end the reassignment nested inside it.
     * Two independent guards meet here: the request's `exists` rule refuses a
     * `deployment` that is a reassignment, and deployments_nested refuses a
     * close that would strand one — translated onto `ends` rather than
     * reaching the browser as a 500.
     */
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

    /** A reassignment is not a placement: the end-placement form cannot name one. */
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

    /**
     * The flash that reports a lost race, which validation can no longer
     * reach: `expects` is now checked against the row before the write, so the
     * only way to the controller's own zero-rows branch is a change landing
     * *between* that check and the UPDATE.
     *
     * Driven by binding a request that skips `after()` — the same device
     * test_end_placement_translates_a_database_refusal_independently_of_validation
     * uses — because a genuine interleaving cannot be produced from a single
     * test process. Without this, the branch would be unreachable code that
     * looks covered.
     */
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

        $this->patch(route('employees.deployments.update', $placement->employee_id), [
            'deployment' => $placement->id, 'expects' => null, 'ends' => '2023-12-31',
        ])->assertSessionHasErrors(['ends' => 'Before the current placement began.']);

        $this->assertNull($placement->fresh()->ends);
    }

    /**
     * Workday rule 3, decision 86. A placement decides which days exist and
     * a workgroup decides which closures reach them, so moving somebody is a
     * recompute event even though a deployment holds no figure.
     */
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

    /**
     * From the earlier of the two end dates, because the days between them
     * change hands either way: pulled in, they become days nobody was
     * employed on; pushed out, days somebody was.
     */
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

    /**
     * The other direction, and the one that distinguishes "the earlier of the
     * two" from "the new one": pushed out, the days between the old end and
     * the new one become days somebody *was* employed on.
     */
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

    /**
     * Two triggers on this table raise P0001 and they ask for opposite
     * remedies. Before decision 86 the SQLSTATE alone decided the message,
     * so a clerk whose September was signed was told to go and end a
     * reassignment that does not exist.
     */
    public function test_a_placement_covering_a_locked_month_names_the_lock_and_not_a_reassignment(): void
    {
        $placement = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $agency = Agency::findOrFail($placement->agency_id);
        $this->actingAsAgency($agency, Permission::ManageOrganization);
        $this->withTenant($agency);
        Ledger::factory()->locked()->create([
            'agency_id' => $agency->id,
            'employee_id' => $placement->employee_id,
            'month' => '2026-09-01',
        ]);

        $this->delete(route('employees.deployments.destroy', [$placement->employee, $placement]))
            ->assertSessionHasErrors(['deployment' => 'That placement covers a locked month. Unlock the ledger first.']);

        $this->assertDatabaseHas('deployments', ['id' => $placement->id]);
    }
}

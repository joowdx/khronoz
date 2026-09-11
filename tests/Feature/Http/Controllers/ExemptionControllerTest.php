<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Exemption;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExemptionControllerTest extends TestCase
{
    public function test_viewing_requires_calendar_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewTerminals);

        $this->get(route('exemptions.index'))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_exemptions_only(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewCalendar);

        Exemption::factory()->create(['agency_id' => $agency->id, 'reference' => 'Mine']);
        Exemption::factory()->create(['reference' => 'Theirs']);

        $this->get(route('exemptions.index'))->assertInertia(
            fn (Assert $page) => $page
                ->component('exemptions/index')
                ->has('exemptions', 1)
                ->where('exemptions.0.reference', 'Mine')
        );
    }

    /** Decision 38: a one-day exemption is `until = date`, never a null. */
    public function test_a_one_day_exemption_ends_on_the_day_it_starts(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('exemptions.store'), [
            'employee_id' => $employee->id,
            'type' => 'pass',
            'date' => '2026-09-15',
            'until' => '2026-09-15',
            'approved_at' => '2026-09-14',
        ])->assertSessionHas('success');

        $exemption = Exemption::sole();

        $this->assertSame('2026-09-15', $exemption->until->toDateString());
        $this->assertFalse($exemption->until->greaterThan($exemption->date));
    }

    /** RA 11210's 105 continuous days is one row, not 105 (decision 37). */
    public function test_a_continuous_leave_is_a_single_row(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('exemptions.store'), [
            'employee_id' => $employee->id,
            'type' => 'leave',
            'date' => '2026-09-01',
            'until' => '2026-12-14',
            'approved_at' => '2026-08-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Exemption::count());
        $this->assertTrue(Exemption::sole()->until->greaterThan(Exemption::sole()->date));
    }

    /**
     * `exemptions_span_is_whole_days`, mirrored so it lands on the field. A
     * 10:00–14:00 window repeated across a statutory leave would have the
     * deriver excuse four hours a day of an entire entitlement.
     */
    public function test_a_multi_day_exemption_cannot_carry_hours(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('exemptions.store'), [
            'employee_id' => $employee->id,
            'type' => 'leave',
            'date' => '2026-09-01',
            'until' => '2026-09-05',
            'starts' => '10:00',
            'ends' => '14:00',
            'approved_at' => '2026-08-01',
        ])->assertSessionHasErrors('starts');

        $this->assertSame(0, Exemption::count());
    }

    public function test_a_single_day_exemption_may_carry_hours(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('exemptions.store'), [
            'employee_id' => $employee->id,
            'type' => 'pass',
            'date' => '2026-09-15',
            'until' => '2026-09-15',
            'starts' => '10:00',
            'ends' => '12:00',
            'approved_at' => '2026-09-14',
        ])->assertSessionHasNoErrors();

        $this->assertSame('10:00:00', Exemption::sole()->starts);
    }

    public function test_an_exemption_cannot_end_before_it_starts(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('exemptions.store'), [
            'employee_id' => $employee->id,
            'type' => 'leave',
            'date' => '2026-09-15',
            'until' => '2026-09-01',
            'approved_at' => '2026-09-01',
        ])->assertSessionHasErrors('until');
    }

    /**
     * The date filters **overlap** rather than contain: a 105-day leave that
     * merely crosses the fortnight being closed is exactly the row a
     * timekeeper must not miss, and a containment filter would drop it.
     */
    public function test_the_date_filter_finds_a_leave_that_merely_crosses_the_period(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        Exemption::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-01',
            'until' => '2026-12-14',
        ]);

        $this->get(route('exemptions.index', ['from' => '2026-10-01', 'to' => '2026-10-15']))
            ->assertInertia(fn (Assert $page) => $page->has('exemptions', 1));
    }

    public function test_the_recording_user_is_the_acting_user(): void
    {
        $agency = Agency::factory()->create();
        $user = $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('exemptions.store'), [
            'employee_id' => $employee->id,
            'type' => 'cto',
            'date' => '2026-09-15',
            'until' => '2026-09-15',
            'approved_at' => '2026-09-14',
        ])->assertSessionHas('success');

        $this->assertSame($user->id, Exemption::sole()->user_id);
    }

    public function test_an_employee_of_another_agency_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('exemptions.store'), [
            'employee_id' => Employee::factory()->create()->id,
            'type' => 'leave',
            'date' => '2026-09-15',
            'until' => '2026-09-15',
            'approved_at' => '2026-09-14',
        ])->assertSessionHasErrors('employee_id');
    }
}

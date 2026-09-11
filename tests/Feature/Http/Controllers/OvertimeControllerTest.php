<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\RemoveEmployee;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Ledger;
use App\Models\Overtime;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OvertimeControllerTest extends TestCase
{
    public function test_viewing_requires_calendar_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewTerminals);

        $this->get(route('overtimes.index'))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_overtime_only(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewCalendar);

        Overtime::factory()->create(['agency_id' => $agency->id, 'purpose' => 'Mine']);
        Overtime::factory()->create(['purpose' => 'Theirs']);

        $this->get(route('overtimes.index'))->assertInertia(
            fn (Assert $page) => $page
                ->component('overtimes/index')
                ->has('overtimes', 1)
                ->where('overtimes.0.purpose', 'Mine')
        );
    }

    /**
     * `overtimes_no_overlap` is a gist exclusion over `tsrange(starts, ends)`
     * and no validation rule can express it, so the controller translates the
     * 23P01 into a message rather than letting a 500 out.
     */
    public function test_overlapping_authorisations_for_one_person_are_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        Overtime::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'starts' => '2026-09-15 17:00:00',
            'ends' => '2026-09-15 20:00:00',
        ]);

        $this->post(route('overtimes.store'), [
            'employee_id' => $employee->id,
            'starts' => '2026-09-15T19:00',
            'ends' => '2026-09-15T22:00',
            'purpose' => 'Overlaps the first',
            'mode' => 'pay',
        ])->assertSessionHas('error');

        $this->assertSame(1, Overtime::count());
    }

    /** Two people may of course work the same hours. */
    public function test_two_people_may_be_authorised_for_the_same_hours(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        foreach (['First', 'Second'] as $purpose) {
            $employee = Employee::factory()->create(['agency_id' => $agency->id]);

            $this->post(route('overtimes.store'), [
                'employee_id' => $employee->id,
                'starts' => '2026-09-15T17:00',
                'ends' => '2026-09-15T20:00',
                'purpose' => $purpose,
                'mode' => 'pay',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Overtime::count());
    }

    /**
     * Back-to-back is not overlapping: `tsrange` is half-open, so a stretch
     * ending at 20:00 and one starting at 20:00 share no instant.
     */
    public function test_back_to_back_authorisations_are_accepted(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        foreach ([['17:00', '20:00'], ['20:00', '22:00']] as [$from, $to]) {
            $this->post(route('overtimes.store'), [
                'employee_id' => $employee->id,
                'starts' => "2026-09-15T{$from}",
                'ends' => "2026-09-15T{$to}",
                'purpose' => 'Year-end closing',
                'mode' => 'pay',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Overtime::count());
    }

    /**
     * The reason both bounds are timestamps: 22:00–02:00 is one stretch of
     * work, and the generated `date` puts it on the day it began — which is
     * how a DTR reads it.
     */
    public function test_an_overnight_stretch_is_one_row_dated_to_the_day_it_began(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('overtimes.store'), [
            'employee_id' => $employee->id,
            'starts' => '2026-09-15T22:00',
            'ends' => '2026-09-16T02:00',
            'purpose' => 'System migration',
            'mode' => 'pay',
        ])->assertSessionHasNoErrors();

        $overtime = Overtime::sole();

        $this->assertSame('2026-09-15', $overtime->date->toDateString());
        $this->assertSame(240, $overtime->minutes());
    }

    public function test_an_authorisation_that_ends_when_it_starts_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('overtimes.store'), [
            'employee_id' => $employee->id,
            'starts' => '2026-09-15T17:00',
            'ends' => '2026-09-15T17:00',
            'purpose' => 'Nothing at all',
            'mode' => 'pay',
        ])->assertSessionHasErrors('ends');
    }

    /**
     * `overtimes_mode_valid` allows `pay` and `cto` and nothing else, so a
     * third value is refused on the field rather than as a 23514.
     */
    public function test_a_mode_the_check_does_not_allow_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('overtimes.store'), [
            'employee_id' => $employee->id,
            'starts' => '2026-09-15T17:00',
            'ends' => '2026-09-15T20:00',
            'purpose' => 'Year-end closing',
            'mode' => 'emergency',
        ])->assertSessionHasErrors('mode');
    }

    /** The picker offers exactly what the CHECK allows — two, not three. */
    public function test_the_form_offers_only_the_modes_the_check_allows(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageCalendar);

        $this->get(route('overtimes.create'))->assertInertia(
            fn (Assert $page) => $page
                ->has('modes', 2)
                ->where('modes.0', ['value' => 'pay', 'label' => 'Overtime pay'])
                ->where('modes.1', ['value' => 'cto', 'label' => 'Compensatory time off'])
        );
    }

    public function test_the_authorising_user_is_the_acting_user(): void
    {
        $agency = Agency::factory()->create();
        $user = $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('overtimes.store'), [
            'employee_id' => $employee->id,
            'starts' => '2026-09-15T17:00',
            'ends' => '2026-09-15T20:00',
            'purpose' => 'Year-end closing',
            'mode' => 'cto',
        ])->assertSessionHas('success');

        $this->assertSame($user->id, Overtime::sole()->user_id);
    }

    public function test_an_employee_of_another_agency_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('overtimes.store'), [
            'employee_id' => Employee::factory()->create()->id,
            'starts' => '2026-09-15T17:00',
            'ends' => '2026-09-15T20:00',
            'purpose' => 'Year-end closing',
            'mode' => 'pay',
        ])->assertSessionHasErrors('employee_id');
    }

    /**
     * The same offboarding lock as on exemptions: an authorised stretch that
     * is already on the record stays correctable after the person is removed,
     * while a *new* employee_id is still held to the picker.
     */
    public function test_an_authorisation_for_a_removed_employee_is_still_correctable(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $overtime = Overtime::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
        ]);

        $this->withTenant($agency);
        app(RemoveEmployee::class)->handle($employee->fresh());

        $this->put(route('overtimes.update', $overtime), [
            'employee_id' => $employee->id,
            'starts' => $overtime->starts->format('Y-m-d\TH:i'),
            'ends' => $overtime->ends->format('Y-m-d\TH:i'),
            'purpose' => 'Year-end closing, per memorandum',
            'mode' => $overtime->mode->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Year-end closing, per memorandum', $overtime->refresh()->purpose);
    }

    public function test_an_authorisation_can_be_withdrawn(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $overtime = Overtime::factory()->create(['agency_id' => $agency->id]);

        $this->delete(route('overtimes.destroy', $overtime))->assertSessionHas('success');

        $this->assertDatabaseMissing('overtimes', ['id' => $overtime->id]);
    }

    public function test_another_agencys_authorisation_is_not_reachable(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageCalendar);

        $this->get(route('overtimes.edit', Overtime::factory()->create()))->assertNotFound();
    }

    /**
     * Decision 81 froze this table against a locked month — `Ledger::view()`
     * reads it live, so a slip withdrawn after the fact would move a figure
     * somebody has signed. Nothing here translated the P0001, so an ordinary
     * withdrawal answered a 500 and the trigger doing its job looked like a
     * fault of the application.
     */
    public function test_withdrawing_an_authority_in_a_locked_month_is_refused_with_a_message(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $overtime = Overtime::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
        ]);
        Ledger::factory()->locked()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'month' => '2026-09-01',
        ]);

        $this->delete(route('overtimes.destroy', $overtime))->assertSessionHas('error');

        $this->assertDatabaseHas('overtimes', ['id' => $overtime->id]);
    }

    /** The exclusion constraint's own message survives the same translation. */
    public function test_overlapping_hours_are_still_refused_by_their_own_message(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Overtime::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'starts' => '2026-09-15 17:00:00',
            'ends' => '2026-09-15 20:00:00',
        ]);

        $this->post(route('overtimes.store'), [
            'employee_id' => $employee->id,
            'starts' => '2026-09-15T18:00',
            'ends' => '2026-09-15T21:00',
            'purpose' => 'Overlaps the first',
            'mode' => 'pay',
        ])->assertSessionHas('error', 'That person is already authorised for overtime over part of those hours.');
    }
}

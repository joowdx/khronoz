<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\RemoveEmployee;
use App\Enums\Permission;
use App\Jobs\FanOutRecompute;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Ledger;
use Illuminate\Support\Facades\Queue;
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

    public function test_extending_a_windowed_exemption_across_days_clears_its_hours(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $exemption = Exemption::factory()->hours('10:00:00', '12:00:00')->create([
            'agency_id' => $agency->id,
            'date' => '2026-09-11',
            'until' => '2026-09-11',
        ]);

        $this->put(route('exemptions.update', $exemption), [
            'employee_id' => $exemption->employee_id,
            'type' => 'leave',
            'date' => '2026-09-11',
            'until' => '2026-09-15',
            'partial' => '0',
            'approved_at' => '2026-09-10',
        ])->assertSessionHasNoErrors();

        $exemption->refresh();

        $this->assertNull($exemption->starts);
        $this->assertNull($exemption->ends);
        $this->assertSame('2026-09-15', $exemption->until->toDateString());
    }

    public function test_turning_the_window_off_clears_the_hours(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $exemption = Exemption::factory()->hours('10:00:00', '12:00:00')->create([
            'agency_id' => $agency->id,
            'date' => '2026-09-11',
            'until' => '2026-09-11',
        ]);

        $this->put(route('exemptions.update', $exemption), [
            'employee_id' => $exemption->employee_id,
            'type' => 'leave',
            'date' => '2026-09-11',
            'until' => '2026-09-11',
            'partial' => '0',
            'approved_at' => '2026-09-10',
        ])->assertSessionHasNoErrors();

        $this->assertNull($exemption->refresh()->starts);
    }

    public function test_the_window_switch_is_never_stored(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('exemptions.store'), [
            'employee_id' => $employee->id,
            'type' => 'pass',
            'date' => '2026-09-15',
            'until' => '2026-09-15',
            'partial' => '1',
            'starts' => '10:00',
            'ends' => '12:00',
            'approved_at' => '2026-09-14',
        ])->assertSessionHasNoErrors();

        $this->assertSame('10:00:00', Exemption::sole()->starts);
        $this->assertArrayNotHasKey('partial', Exemption::sole()->getAttributes());
    }

    public function test_an_exemption_of_a_removed_employee_is_still_correctable(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $exemption = Exemption::factory()->spanning(105)->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-01-05',
        ]);

        $this->withTenant($agency);
        app(RemoveEmployee::class)->handle($employee->fresh());

        $this->put(route('exemptions.update', $exemption), [
            'employee_id' => $employee->id,
            'type' => 'leave',
            'date' => '2026-01-05',
            'until' => '2026-04-19',
            'partial' => '0',
            'remarks' => 'Maternity, RA 11210',
            'approved_at' => '2025-12-29',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Maternity, RA 11210', $exemption->refresh()->remarks);
    }

    public function test_an_exemption_cannot_be_moved_onto_a_removed_employee(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $exemption = Exemption::factory()->create(['agency_id' => $agency->id]);
        $removed = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->withTenant($agency);
        app(RemoveEmployee::class)->handle($removed->fresh());

        $this->put(route('exemptions.update', $exemption), [
            'employee_id' => $removed->id,
            'type' => 'leave',
            'date' => $exemption->date->toDateString(),
            'until' => $exemption->until->toDateString(),
            'partial' => '0',
            'approved_at' => '2026-09-01',
        ])->assertSessionHasErrors('employee_id');
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

    public function test_recording_an_exemption_queues_a_recompute_over_its_days(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        Queue::fake([FanOutRecompute::class]);

        $this->post(route('exemptions.store'), [
            'employee_id' => $employee->id,
            'type' => 'leave',
            'date' => '2026-09-15',
            'until' => '2026-09-17',
            'approved_at' => '2026-09-14',
        ])->assertSessionHas('success');

        $this->assertQueued($employee->id, '2026-09-15', '2026-09-17');
    }

    public function test_correcting_the_dates_queues_a_recompute_over_both_spans(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $exemption = Exemption::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-05',
            'until' => '2026-09-06',
        ]);

        Queue::fake([FanOutRecompute::class]);

        $this->put(route('exemptions.update', $exemption), [
            'employee_id' => $employee->id,
            'type' => $exemption->type->value,
            'date' => '2026-09-20',
            'until' => '2026-09-21',
            'approved_at' => '2026-09-04',
        ])->assertSessionHas('success');

        $this->assertQueued($employee->id, '2026-09-05', '2026-09-21');
    }

    public function test_withdrawing_an_exemption_queues_a_recompute(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $exemption = Exemption::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-15',
            'until' => '2026-09-15',
        ]);

        Queue::fake([FanOutRecompute::class]);

        $this->delete(route('exemptions.destroy', $exemption))->assertSessionHas('success');

        $this->assertQueued($employee->id, '2026-09-15', '2026-09-15');
    }

    public function test_an_exemption_reaching_a_locked_month_is_refused_with_a_message(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Ledger::factory()->locked()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'month' => '2026-08-01',
        ]);

        $this->post(route('exemptions.store'), [
            'employee_id' => $employee->id,
            'type' => 'leave',
            'date' => '2026-08-15',
            'until' => '2026-08-15',
            'approved_at' => '2026-08-14',
        ])->assertSessionHas('error');

        $this->assertSame(0, Exemption::query()->count());
    }

    private function assertQueued(string $employeeId, string $from, string $to): void
    {
        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->employeeIds === [$employeeId]
                && $job->from === $from
                && $job->to === $to,
        );
    }
}

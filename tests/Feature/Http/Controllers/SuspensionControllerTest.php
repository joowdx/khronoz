<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Jobs\FanOutRecompute;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Suspension;
use App\Models\Workgroup;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuspensionControllerTest extends TestCase
{
    public function test_viewing_requires_calendar_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewTerminals);

        $this->get(route('suspensions.index'))->assertForbidden();
    }

    public function test_the_index_lists_this_agencys_suspensions_only(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewCalendar);

        Suspension::factory()->create(['agency_id' => $agency->id, 'date' => '2026-07-22', 'reason' => 'Typhoon']);
        Suspension::factory()->create(['reason' => 'Somebody else']);

        $this->get(route('suspensions.index', ['year' => 2026]))->assertInertia(
            fn (Assert $page) => $page
                ->component('suspensions/index')
                ->has('suspensions', 1)
                ->where('suspensions.0.reason', 'Typhoon')
        );
    }

    public function test_a_suspension_with_no_workgroup_covers_the_agency(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Typhoon Signal No. 3',
            'declared_at' => '2026-07-22',
        ])->assertSessionHas('success');

        $suspension = Suspension::sole();

        $this->assertNull($suspension->workgroup_id);
        $this->assertNull($suspension->starts);
        $this->assertNull($suspension->ends);
    }

    public function test_turning_the_window_off_clears_the_hours(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $suspension = Suspension::factory()->create([
            'agency_id' => $agency->id,
            'date' => '2026-07-22',
            'starts' => '12:00:00',
            'ends' => '17:00:00',
        ]);

        $this->put(route('suspensions.update', $suspension), [
            'date' => '2026-07-22',
            'reason' => 'Typhoon Signal No. 3',
            'partial' => '0',
            'declared_at' => '2026-07-22',
        ])->assertSessionHasNoErrors();

        $suspension->refresh();

        $this->assertNull($suspension->starts);
        $this->assertNull($suspension->ends);
    }

    public function test_the_declaring_user_is_the_acting_user(): void
    {
        $agency = Agency::factory()->create();
        $user = $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Brownout',
            'declared_at' => '2026-07-22',
        ])->assertSessionHas('success');

        $this->assertSame($user->id, Suspension::sole()->user_id);
    }

    public function test_a_half_set_window_is_refused_on_the_field(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Brownout',
            'declared_at' => '2026-07-22',
            'starts' => '12:00',
        ])->assertSessionHasErrors('ends');

        $this->assertSame(0, Suspension::count());
    }

    public function test_a_window_that_ends_when_it_starts_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Brownout',
            'declared_at' => '2026-07-22',
            'starts' => '12:00',
            'ends' => '12:00',
        ])->assertSessionHasErrors('ends');
    }

    public function test_one_date_may_carry_more_than_one_suspension(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        foreach ([['08:00', '12:00', 'Brownout, morning'], ['13:00', '17:00', 'Brownout, afternoon']] as [$from, $to, $why]) {
            $this->post(route('suspensions.store'), [
                'date' => '2026-07-22',
                'reason' => $why,
                'declared_at' => '2026-07-22',
                'starts' => $from,
                'ends' => $to,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Suspension::count());
    }

    public function test_a_workgroup_of_another_agency_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Typhoon',
            'declared_at' => '2026-07-22',
            'workgroup_id' => Workgroup::factory()->create()->id,
        ])->assertSessionHasErrors('workgroup_id');
    }

    public function test_a_suspension_can_be_withdrawn(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $suspension = Suspension::factory()->create(['agency_id' => $agency->id]);

        $this->delete(route('suspensions.destroy', $suspension))->assertSessionHas('success');

        $this->assertDatabaseMissing('suspensions', ['id' => $suspension->id]);
    }

    public function test_another_agencys_suspension_is_not_reachable(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ManageCalendar);

        $this->get(route('suspensions.edit', Suspension::factory()->create()))->assertNotFound();
    }

    public function test_an_agency_wide_suspension_queues_the_whole_agency(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);

        Queue::fake([FanOutRecompute::class]);

        $this->post(route('suspensions.store'), [
            'date' => '2026-07-22',
            'reason' => 'Typhoon Signal No. 3',
            'declared_at' => '2026-07-22',
        ])->assertSessionHas('success');

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->agencyId === $agency->id
                && $job->employeeIds === null
                && $job->from === '2026-07-22'
                && $job->to === '2026-07-22',
        );
    }

    public function test_a_workgroup_suspension_queues_only_its_people(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id]);
        $inside = $this->deployed($agency, $workgroup);
        $outside = $this->deployed($agency, Workgroup::factory()->create(['agency_id' => $agency->id]));

        Queue::fake([FanOutRecompute::class]);

        $this->post(route('suspensions.store'), [
            'workgroup_id' => $workgroup->id,
            'date' => '2026-07-22',
            'reason' => 'Water interruption',
            'declared_at' => '2026-07-22',
        ])->assertSessionHas('success');

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->agencyId === null
                && $job->employeeIds === [$inside->id],
        );
        Queue::assertNotPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => in_array($outside->id, $job->employeeIds ?? [], true),
        );
    }

    public function test_moving_a_suspension_queues_both_dates(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $suspension = Suspension::factory()->create([
            'agency_id' => $agency->id,
            'workgroup_id' => null,
            'date' => '2026-07-22',
        ]);

        Queue::fake([FanOutRecompute::class]);

        $this->put(route('suspensions.update', $suspension), [
            'date' => '2026-07-23',
            'reason' => $suspension->reason,
            'declared_at' => '2026-07-23',
        ])->assertSessionHas('success');

        Queue::assertPushedTimes(FanOutRecompute::class, 2);
        Queue::assertPushed(FanOutRecompute::class, fn (FanOutRecompute $job): bool => $job->from === '2026-07-22');
        Queue::assertPushed(FanOutRecompute::class, fn (FanOutRecompute $job): bool => $job->from === '2026-07-23');
    }

    public function test_correcting_the_reason_queues_the_date_once(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $suspension = Suspension::factory()->create([
            'agency_id' => $agency->id,
            'workgroup_id' => null,
            'date' => '2026-07-22',
        ]);

        Queue::fake([FanOutRecompute::class]);

        $this->put(route('suspensions.update', $suspension), [
            'date' => '2026-07-22',
            'reason' => 'Typhoon Signal No. 4',
            'declared_at' => '2026-07-22',
        ])->assertSessionHas('success');

        Queue::assertPushedTimes(FanOutRecompute::class, 1);
    }

    public function test_withdrawing_a_suspension_queues_its_date(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageCalendar);
        $suspension = Suspension::factory()->create([
            'agency_id' => $agency->id,
            'workgroup_id' => null,
            'date' => '2026-07-22',
        ]);

        Queue::fake([FanOutRecompute::class]);

        $this->delete(route('suspensions.destroy', $suspension))->assertSessionHas('success');

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->agencyId === $agency->id
                && $job->from === '2026-07-22',
        );
    }

    private function deployed(Agency $agency, Workgroup $workgroup): Employee
    {
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'workgroup_id' => $workgroup->id,
            'employee_id' => $employee->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        return $employee;
    }
}

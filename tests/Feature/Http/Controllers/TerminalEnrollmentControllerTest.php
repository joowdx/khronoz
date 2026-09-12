<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\RemoveEmployee;
use App\Enums\EnrollmentPrivilege;
use App\Enums\Permission;
use App\Http\Requests\EndEnrollmentRequest;
use App\Jobs\RecomputeWorkdays;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Terminal;
use App\Models\Timelog;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TerminalEnrollmentControllerTest extends TestCase
{
    private function terminal(Agency $agency): Terminal
    {
        return Terminal::factory()->create(['agency_id' => $agency->id]);
    }

    public function test_viewing_requires_terminals_view(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewCalendar);

        $this->get(route('terminals.enrollments.index', $this->terminal($agency)))->assertForbidden();
    }

    public function test_enrolling_requires_terminals_manage(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);
        $terminal = $this->terminal($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('terminals.enrollments.store', $terminal), [
            'employee_id' => $employee->id,
            'uid' => '0042',
            'privilege' => 'user',
            'starts' => '2026-01-01',
        ])->assertForbidden();
    }

    public function test_the_index_lists_current_enrollments_first(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewTerminals);
        $terminal = $this->terminal($agency);

        Enrollment::factory()->on($terminal)->create([
            'uid' => '0001',
            'starts' => today()->subYear()->toDateString(),
            'ends' => today()->subMonth()->toDateString(),
        ]);
        Enrollment::factory()->on($terminal)->create(['uid' => '0002', 'starts' => today()->subWeek()->toDateString()]);

        $this->get(route('terminals.enrollments.index', $terminal))->assertInertia(
            fn (Assert $page) => $page
                ->component('terminals/enrollments/index')
                ->has('enrollments', 2)
                ->where('enrollments.0.uid', '0002')
                ->where('enrollments.0.current', true)
                ->where('enrollments.1.current', false)
        );
    }

    public function test_somebody_can_be_enrolled(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('terminals.enrollments.store', $terminal), [
            'employee_id' => $employee->id,
            'uid' => '0042',
            'privilege' => 'user',
            'starts' => '2026-01-01',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('enrollments', [
            'terminal_id' => $terminal->id,
            'employee_id' => $employee->id,
            'uid' => '0042',
        ]);
    }

    public function test_a_device_user_id_with_a_space_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('terminals.enrollments.store', $terminal), [
            'employee_id' => $employee->id,
            'uid' => '00 42',
            'privilege' => 'user',
            'starts' => '2026-01-01',
        ])->assertSessionHasErrors('uid');
    }

    public function test_a_leading_zero_survives(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('terminals.enrollments.store', $terminal), [
            'employee_id' => $employee->id,
            'uid' => '007',
            'privilege' => 'user',
            'starts' => '2026-01-01',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enrollments', ['uid' => '007']);
        $this->assertDatabaseMissing('enrollments', ['uid' => '7']);
    }

    public function test_a_taken_device_user_id_is_refused_with_a_message_naming_it(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);

        Enrollment::factory()->on($terminal)->create(['uid' => '0042', 'starts' => '2026-01-01']);
        $newcomer = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('terminals.enrollments.store', $terminal), [
            'employee_id' => $newcomer->id,
            'uid' => '0042',
            'privilege' => 'user',
            'starts' => '2026-06-01',
        ])->assertSessionHas('error', fn (string $message) => str_contains($message, '0042'));

        $this->assertSame(1, Enrollment::where('uid', '0042')->count());
    }

    public function test_enrolling_the_same_person_twice_on_one_terminal_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $existing = Enrollment::factory()->on($terminal)->create(['uid' => '0042', 'starts' => '2026-01-01']);

        $this->post(route('terminals.enrollments.store', $terminal), [
            'employee_id' => $existing->employee_id,
            'uid' => '0099',
            'privilege' => 'user',
            'starts' => '2026-06-01',
        ])->assertSessionHas('error', fn (string $message) => str_contains($message, 'already enrolled'));
    }

    public function test_a_device_user_id_can_be_reissued_once_the_first_enrollment_has_ended(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);

        $leaver = Enrollment::factory()->on($terminal)->create([
            'uid' => '0042',
            'starts' => '2026-01-01',
            'ends' => '2026-05-31',
        ]);
        $joiner = Employee::factory()->create(['agency_id' => $agency->id]);

        $this->post(route('terminals.enrollments.store', $terminal), [
            'employee_id' => $joiner->id,
            'uid' => '0042',
            'privilege' => 'user',
            'starts' => '2026-06-01',
        ])->assertSessionHas('success');

        $this->assertSame(2, Enrollment::where('uid', '0042')->count());
        $this->assertNotSame($leaver->employee_id, $joiner->id);
    }

    public function test_an_enrollment_can_be_ended(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $enrollment = Enrollment::factory()->on($terminal)->create(['starts' => '2026-01-01']);

        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), ['ends' => '2026-09-30', 'expects' => ''])
            ->assertSessionHas('success');

        $this->assertSame('2026-09-30', $enrollment->fresh()->ends->toDateString());
    }

    public function test_an_enrollment_cannot_be_ended_before_it_started(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $enrollment = Enrollment::factory()->on($terminal)->create(['starts' => '2026-06-01']);

        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), ['ends' => '2026-01-01', 'expects' => ''])
            ->assertSessionHasErrors('ends');

        $this->assertNull($enrollment->fresh()->ends);
    }

    public function test_ending_an_enrollment_unresolves_the_punches_it_no_longer_covers(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $enrollment = Enrollment::factory()->on($terminal)->create(['starts' => '2026-01-01']);
        $punch = Timelog::factory()->resolving($enrollment)->create();

        $this->assertNotNull($punch->fresh()->employee_id);

        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), [
            'ends' => $punch->time->copy()->subDay()->toDateString(),
            'expects' => '',
        ])->assertSessionHas('success');

        $this->assertNull($punch->fresh()->employee_id);
    }

    public function test_ending_an_enrollment_onto_its_successor_is_refused_with_a_message(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $first = Enrollment::factory()->on($terminal)
            ->create(['uid' => '0042', 'starts' => '2026-01-01', 'ends' => '2026-09-05']);
        Enrollment::factory()->on($terminal)
            ->create(['uid' => '0042', 'starts' => '2026-09-06']);

        $this->patch(route('terminals.enrollments.update', [$terminal, $first]), [
            'ends' => '2026-09-30',
            'expects' => '2026-09-05',
        ])->assertSessionHas('error');

        $this->assertSame('2026-09-05', $first->fresh()->ends->toDateString());
    }

    public function test_a_stale_form_cannot_move_an_end_date_already_recorded(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $enrollment = Enrollment::factory()->on($terminal)->create(['starts' => '2026-01-01']);

        // The page is rendered while the enrollment is open, so it carries no
        // expected end. Somebody else ends it before this form is submitted.
        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), [
            'ends' => '2026-09-10',
            'expects' => '',
        ])->assertSessionHas('success');

        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), [
            'ends' => '2026-09-05',
            'expects' => '',
        ])->assertSessionHasErrors('ends');

        $this->assertSame('2026-09-10', $enrollment->fresh()->ends->toDateString());
    }

    public function test_ending_without_the_expected_end_date_is_refused(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $enrollment = Enrollment::factory()->on($terminal)->create(['starts' => '2026-01-01']);

        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), ['ends' => '2026-09-30'])
            ->assertSessionHasErrors('expects');

        $this->assertNull($enrollment->fresh()->ends);
    }

    public function test_a_change_landing_after_validation_reports_a_lost_race(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $enrollment = Enrollment::factory()->on($terminal)->create(['starts' => '2026-01-01']);

        $this->app->bind(EndEnrollmentRequest::class, fn () => new class extends EndEnrollmentRequest
        {
            public function after(): array
            {
                return [];
            }
        });

        // `expects` names a value the row does not hold, standing in for a
        // change committed after validation passed.
        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), [
            'ends' => '2026-09-30',
            'expects' => '2026-01-31',
        ])->assertSessionHas('error');

        $this->assertNull($enrollment->fresh()->ends, 'the row must be untouched');
    }

    public function test_the_roster_survives_an_offboarded_employee(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Enrollment::factory()->on($terminal)
            ->create(['employee_id' => $employee->id, 'uid' => '0042', 'starts' => '2026-01-01']);
        Enrollment::factory()->on($terminal)->create(['uid' => '0043', 'starts' => '2026-06-01']);

        $this->withTenant($agency);
        app(RemoveEmployee::class)->handle($employee->fresh());

        $this->get(route('terminals.enrollments.index', $terminal))->assertInertia(
            fn (Assert $page) => $page
                ->component('terminals/enrollments/index')
                ->has('enrollments', 2)
                // The removed person's row is still listed, and still says
                // which device user id was theirs — that is the whole point
                // of keeping it.
                ->where('enrollments.0.uid', '0043')
                ->where('enrollments.1.uid', '0042')
                ->where('enrollments.1.employee', null)
        );
    }

    public function test_the_privilege_is_labelled_by_the_enum(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        Enrollment::factory()->on($terminal)
            ->create(['uid' => '0042', 'privilege' => EnrollmentPrivilege::Superadmin]);

        $this->get(route('terminals.enrollments.index', $terminal))->assertInertia(
            fn (Assert $page) => $page
                ->where('enrollments.0.privilege.value', 'superadmin')
                ->where('enrollments.0.privilege.label', 'Super administrator')
                ->where('privileges', EnrollmentPrivilege::choices())
        );
    }

    public function test_an_enrollment_of_another_terminal_is_not_reachable(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $mine = $this->terminal($agency);
        $theirs = Enrollment::factory()->create(['agency_id' => $agency->id]);

        $this->patch(route('terminals.enrollments.update', [$mine, $theirs]), ['ends' => '2026-09-30'])
            ->assertNotFound();
    }

    public function test_enrolling_queues_a_recompute_for_the_punches_it_claims(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $orphan = Timelog::factory()->create([
            'agency_id' => $agency->id,
            'terminal_id' => $terminal->id,
            'uid' => '0042',
            'time' => '2026-02-10 08:00:00',
        ]);
        $this->assertNull($orphan->fresh()->employee_id);

        Queue::fake([RecomputeWorkdays::class]);

        $this->post(route('terminals.enrollments.store', $terminal), [
            'employee_id' => $employee->id,
            'uid' => '0042',
            'privilege' => 'user',
            'starts' => '2026-01-01',
        ])->assertSessionHas('success');

        Queue::assertPushed(
            RecomputeWorkdays::class,
            fn (RecomputeWorkdays $job): bool => $job->employeeId === $employee->id
                && $job->from === '2026-02-07'
                && $job->to === '2026-02-10',
        );
    }

    public function test_ending_an_enrollment_queues_a_recompute_for_the_punch_it_releases(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $enrollment = Enrollment::factory()->on($terminal)->create(['starts' => '2026-01-01']);
        $punch = Timelog::factory()->resolving($enrollment)->create(['time' => '2026-02-10 08:00:00']);
        $employeeId = $punch->fresh()->employee_id;

        Queue::fake([RecomputeWorkdays::class]);

        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), [
            'ends' => '2026-02-09',
            'expects' => '',
        ])->assertSessionHas('success');

        $this->assertNull($punch->fresh()->employee_id);
        Queue::assertPushed(
            RecomputeWorkdays::class,
            fn (RecomputeWorkdays $job): bool => $job->employeeId === $employeeId
                && $job->from === '2026-02-07'
                && $job->to === '2026-02-10',
        );
    }

    public function test_a_stale_form_queues_no_recompute(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $enrollment = Enrollment::factory()->on($terminal)->create(['starts' => '2026-01-01', 'ends' => '2026-03-31']);
        Timelog::factory()->resolving($enrollment)->create(['time' => '2026-02-10 08:00:00']);

        Queue::fake([RecomputeWorkdays::class]);

        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), [
            'ends' => '2026-02-09',
            'expects' => '',
        ])->assertSessionHasErrors('ends');

        Queue::assertNotPushed(RecomputeWorkdays::class);
    }
}

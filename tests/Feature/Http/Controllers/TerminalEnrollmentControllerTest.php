<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Terminal;
use App\Models\Timelog;
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

    /** Decision 42, at the boundary: a padded uid never matches what the device reports. */
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

    /** Decision 42 again: `007` is stored as `007`, never normalised to `7`. */
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

    /**
     * `enrollments_uid_one_person`, translated. The constraint is an exclusion
     * over a date range, which no validation rule can express, so the
     * controller turns the 23P01 into a message naming which rule broke.
     */
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

    /** `enrollments_one_uid_per_person` — the other exclusion, a different problem and a different message. */
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

    /**
     * The reissue case, which is the whole reason enrollments are a range: once
     * the first holder's row is ended, the device user id is free again.
     */
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

        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), ['ends' => '2026-09-30'])
            ->assertSessionHas('success');

        $this->assertSame('2026-09-30', $enrollment->fresh()->ends->toDateString());
    }

    /** `enrollments_dates_ordered`, translated rather than surfacing as a 500. */
    public function test_an_enrollment_cannot_be_ended_before_it_started(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $terminal = $this->terminal($agency);
        $enrollment = Enrollment::factory()->on($terminal)->create(['starts' => '2026-06-01']);

        $this->patch(route('terminals.enrollments.update', [$terminal, $enrollment]), ['ends' => '2026-01-01'])
            ->assertSessionHas('error');

        $this->assertNull($enrollment->fresh()->ends);
    }

    /**
     * Ending an enrollment re-resolves the punches it no longer covers — the
     * trigger's detach pass, reached through the UI rather than through SQL.
     */
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
        ])->assertSessionHas('success');

        $this->assertNull($punch->fresh()->employee_id);
    }

    /** An enrollment of another terminal is a 404, not something this route can end. */
    public function test_an_enrollment_of_another_terminal_is_not_reachable(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageTerminals);
        $mine = $this->terminal($agency);
        $theirs = Enrollment::factory()->create(['agency_id' => $agency->id]);

        $this->patch(route('terminals.enrollments.update', [$mine, $theirs]), ['ends' => '2026-09-30'])
            ->assertNotFound();
    }
}

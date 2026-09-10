<?php

namespace Tests\Feature\Actions;

use App\Actions\SeparateEmployee;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class SeparateEmployeeTest extends TestCase
{
    /**
     * The whole point of the action: nothing else in the application closes a
     * deployment except a move, so before this existed a separated employee
     * kept an open placement and Employee::currentDeployment() — a
     * hasOne(Deployment)->whereNull('ends'), which Milestone 3's rosters and
     * Milestone 6's daily time records both resolve through — still answered
     * with a unit.
     *
     * `ends` is the separation date itself, not the day before: the day
     * someone leaves is a day they were still in that unit, and it is the day
     * their last daily time record belongs to.
     */
    public function test_closes_the_open_placement_on_the_day_they_left(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);

        $placement = Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'starts' => '2024-06-01',
            'ends' => null,
        ]);

        app(SeparateEmployee::class)->handle($employee, ['separated_at' => '2026-07-13']);

        $this->assertSame('2026-07-13', $placement->fresh()->ends->toDateString());
        $this->assertSame('2026-07-13', $employee->fresh()->separated_at->toDateString());
        $this->assertNull($employee->fresh()->currentDeployment);
        // Closed, never superseded: a separation opens nothing.
        $this->assertSame(1, Deployment::query()->where('employee_id', $employee->id)->count());
    }

    /** Nobody to close: an employee whose last placement already ended, or who never had one, just gets the date. */
    public function test_separates_an_employee_with_no_open_placement(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);

        $closed = Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'starts' => '2021-01-01',
            'ends' => '2022-12-31',
        ]);

        app(SeparateEmployee::class)->handle($employee, ['separated_at' => '2026-07-13']);

        $this->assertSame('2026-07-13', $employee->fresh()->separated_at->toDateString());
        $this->assertSame('2022-12-31', $closed->fresh()->ends->toDateString());
    }

    /**
     * No pre-check on the date (R16's relationship with the constraints, R19):
     * a separation earlier than the open placement's own `starts` would leave
     * `ends < starts`, and `deployments_dates_ordered` is what refuses it.
     * UpdateEmployeeRequest keeps a clerk from reaching this, and
     * EmployeeController::update translates the refusal — neither is this
     * action's job.
     */
    public function test_refuses_a_separation_before_the_placement_began(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);

        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'starts' => '2024-06-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('23514', fn () => app(SeparateEmployee::class)->handle($employee, ['separated_at' => '2021-06-01']));
    }

    /**
     * The "one transaction" half: a record that says someone has left must
     * never survive without the placement close that goes with it.
     *
     * Deliberately not assertDatabaseRefuses(), for the reason
     * MoveEmployeeTest::test_a_failed_open_rolls_back_the_close spells out —
     * that helper runs the statement in its own DB::transaction(), which
     * nested inside the per-test transaction is a SAVEPOINT that rolls back
     * whether or not handle() transacts, so it would prove nothing about this.
     * Catching by hand leaves the refused UPDATE's damage sitting in the
     * surrounding transaction: with handle()'s own DB::transaction() in place
     * the failure rolls back to its savepoint and the read below succeeds;
     * without it, Postgres marks the per-test transaction aborted (25P02) and
     * the read errors instead.
     */
    public function test_a_refused_close_rolls_back_the_separation(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);

        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unit->id,
            'starts' => '2024-06-01',
            'ends' => null,
        ]);

        try {
            app(SeparateEmployee::class)->handle($employee, ['separated_at' => '2021-06-01']);
            $this->fail('expected deployments_dates_ordered to refuse a close before the placement began');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }

        $this->assertNull($employee->fresh()->separated_at);
    }
}

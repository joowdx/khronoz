<?php

namespace Tests\Feature\Actions;

use App\Actions\MoveEmployee;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MoveEmployeeTest extends TestCase
{
    public function test_opens_the_first_deployment_for_an_employee_with_none(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $unit = Unit::factory()->create(['agency_id' => $agency->id]);

        $deployment = app(MoveEmployee::class)->handle($employee, $unit, Carbon::parse('2026-01-01'));

        $this->assertSame($unit->id, $deployment->unit_id);
        $this->assertSame($employee->id, $deployment->employee_id);
        $this->assertSame($agency->id, $deployment->agency_id);
        $this->assertSame('2026-01-01', $deployment->starts->toDateString());
        $this->assertNull($deployment->ends);
        $this->assertSame(1, Deployment::query()->where('employee_id', $employee->id)->count());
    }

    /** R16: close first, then open — the day before, so the two ranges are contiguous rather than overlapping. */
    public function test_closes_the_open_deployment_the_day_before_the_new_one_starts(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $unitA = Unit::factory()->create(['agency_id' => $agency->id]);
        $unitB = Unit::factory()->create(['agency_id' => $agency->id]);

        $original = Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unitA->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $new = app(MoveEmployee::class)->handle($employee->fresh(), $unitB, Carbon::parse('2026-03-15'));

        $this->assertSame('2026-03-14', $original->fresh()->ends->toDateString());
        $this->assertSame($unitB->id, $new->unit_id);
        $this->assertSame('2026-03-15', $new->starts->toDateString());
        $this->assertNull($new->ends);
    }

    /**
     * No pre-check: the exclusion constraint is the only thing standing
     * between this and a silently corrupt double-booking (R16). The fixture
     * is a *closed*, historical deployment lying in the range the new open
     * deployment would cover. currentDeployment (whereNull ends) does not
     * see a closed row, so MoveEmployee has no "close" step to run first —
     * only the database's own deployments_no_overlap catches the collision,
     * exactly the case the action is written to let happen rather than
     * pre-empt.
     *
     * A legally-open current deployment can never reach this path: any
     * starts valid enough to close it without itself violating
     * deployments_dates_ordered is, by construction, later than every prior
     * row for that employee, so it can never overlap one. This is the only
     * shape that reaches the exclusion constraint through this action.
     */
    public function test_refuses_an_overlap(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $unitA = Unit::factory()->create(['agency_id' => $agency->id]);
        $unitB = Unit::factory()->create(['agency_id' => $agency->id]);

        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unitA->id,
            'starts' => '2025-06-01',
            'ends' => '2025-09-01',
        ]);

        $this->assertDatabaseRefuses('23P01', fn () => app(MoveEmployee::class)->handle($employee->fresh(), $unitB, Carbon::parse('2025-07-01')));

        // The historical row survives untouched.
        $this->assertDatabaseHas('deployments', ['employee_id' => $employee->id, 'starts' => '2025-06-01', 'ends' => '2025-09-01']);
    }

    /**
     * Proves the "one transaction" half of R16 directly: the close (a valid
     * UPDATE that would succeed on its own) and the open (an INSERT that
     * fails on a mismatched agency_id/unit_id pair) either both land or
     * neither does. $foreignUnit belongs to a different agency, so the
     * insert fails with 23503 (foreign_key_violation) on
     * deployments_unit_id_agency_id_foreign — a failure with nothing to do
     * with overlap, chosen so this test is not just a rerun of
     * test_refuses_an_overlap under a different name.
     */
    public function test_a_failed_open_rolls_back_the_close(): void
    {
        $foreignUnit = Unit::factory()->create(); // a different, unrelated agency

        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id, 'hired_at' => '2020-01-01']);
        $unitA = Unit::factory()->create(['agency_id' => $agency->id]);

        $current = Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'unit_id' => $unitA->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        // Deliberately not assertDatabaseRefuses(): per its own docblock
        // (tests/TestCase.php), it runs $statement inside its own
        // DB::transaction(), which — nested inside the per-test transaction
        // already open here — compiles to a SAVEPOINT and rolls back
        // automatically once the QueryException is caught. That would undo
        // the close by the *test harness itself*, regardless of whether
        // MoveEmployee::handle() wraps its own work in a transaction — so an
        // assertDatabaseRefuses version of this test would pass even with
        // DB::transaction() deleted from handle(), proving nothing about the
        // one thing this test exists to check. Catching by hand instead
        // leaves the failed INSERT's damage sitting directly in the
        // surrounding per-test transaction: with handle()'s own
        // DB::transaction() in place, the failure rolls back only to that
        // inner savepoint and the read below succeeds; without it, Postgres
        // marks the whole per-test transaction aborted (25P02) and the same
        // read errors instead of passing.
        try {
            app(MoveEmployee::class)->handle($employee->fresh(), $foreignUnit, Carbon::parse('2026-06-01'));
            $this->fail('expected the paired FK to refuse a unit from another agency');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }

        $this->assertNull($current->fresh()->ends);
    }
}

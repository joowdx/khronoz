<?php

namespace Tests\Feature\Models;

use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ledgers_agency_id_foreign is untested on both sides for the reason Ruling
 * P5 gives on deployments: the paired FK requires a real employee of that
 * agency, so no row exists where this FK alone fails. Its delete side is
 * covered transitively by the employees agency-delete test.
 *
 * No agency_not_platform test either: a ledger needs an employee, and
 * `employees` refuses the platform agency already.
 */
class LedgerTest extends TestCase
{
    /** @return array<string, mixed> */
    private function ledgerRow(Ledger $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'employee_id' => $like->employee_id,
            'month' => $like->month->toDateString(),
            'locked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    /**
     * A placement whose employee already has September 2026 locked. Range
     * is the caller's: closed-before-September for insert tests, open for
     * coverage-change tests.
     *
     * @param  array<string, mixed>  $range
     */
    private function placementLockedInSeptember(array $range): Deployment
    {
        $deployment = Deployment::factory()->create($range);

        Ledger::factory()->locked()->create([
            'agency_id' => $deployment->agency_id,
            'employee_id' => $deployment->employee_id,
            'month' => '2026-09-01',
        ]);

        return $deployment;
    }

    public function test_ledger_needs_an_agency(): void
    {
        $ledger = Ledger::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('ledgers')->insert(
            $this->ledgerRow($ledger, ['agency_id' => null])
        ));
    }

    /**
     * employee_id NOT NULL, and one nullable column would defeat the tenancy
     * guarantee too: MATCH SIMPLE skips a paired FK entirely once a
     * referencing column is null.
     */
    public function test_employee_is_required(): void
    {
        $ledger = Ledger::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('ledgers')->insert(
            $this->ledgerRow($ledger, ['employee_id' => null])
        ));
    }

    public function test_month_is_required(): void
    {
        $ledger = Ledger::factory()->create();

        $this->assertDatabaseRefuses(
            '23502',
            fn () => DB::table('ledgers')->insert($this->ledgerRow($ledger, ['month' => null])),
            'column "month"',
        );
    }

    /** ledgers_month_is_first_of_month. */
    public function test_month_must_be_the_first_of_the_month(): void
    {
        $ledger = Ledger::factory()->create();

        $this->assertDatabaseRefuses(
            '23514',
            fn () => DB::table('ledgers')->insert($this->ledgerRow($ledger, ['month' => '2026-09-15'])),
            'ledgers_month_is_first_of_month',
        );
    }

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'ledgers_id_agency_id_unique'"));
    }

    /**
     * The target `workdays` pairs against, so a workday is provably that
     * employee's own month. Nothing references it yet, which is why only the
     * catalog can be asserted.
     */
    public function test_id_employee_id_and_month_are_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'ledgers_id_employee_id_month_unique'"));
    }

    /** ledgers_employee_id_month_unique. */
    public function test_one_employee_cannot_have_two_ledgers_for_the_same_month(): void
    {
        $ledger = Ledger::factory()->create(['month' => '2026-09-01']);

        $this->assertDatabaseRefuses(
            '23505',
            fn () => DB::table('ledgers')->insert($this->ledgerRow($ledger)),
            'ledgers_employee_id_month_unique',
        );
    }

    public function test_one_employee_may_have_ledgers_for_different_months(): void
    {
        $first = Ledger::factory()->create(['month' => '2026-09-01']);

        $second = Ledger::factory()->create([
            'agency_id' => $first->agency_id,
            'employee_id' => $first->employee_id,
            'month' => '2026-10-01',
        ]);

        $this->assertDatabaseHas('ledgers', ['id' => $second->id]);
    }

    public function test_two_employees_may_have_a_ledger_for_the_same_month(): void
    {
        $first = Ledger::factory()->create(['month' => '2026-09-01']);

        $second = Ledger::factory()->create([
            'agency_id' => $first->agency_id,
            'month' => '2026-09-01',
        ]);

        $this->assertDatabaseHas('ledgers', ['id' => $second->id]);
        $this->assertNotSame($first->employee_id, $second->employee_id);
    }

    /** ledgers_employee_id_agency_id_foreign, insert side. */
    public function test_employee_must_share_the_ledgers_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Ledger::factory()->create(['employee_id' => $employee->id]));
    }

    /**
     * Same FK, delete side. A raw DELETE, not $employee->delete(): employees
     * are soft deleted, so the Eloquent call is an UPDATE the FK never sees.
     */
    public function test_employee_with_a_ledger_cannot_be_hard_deleted(): void
    {
        $ledger = Ledger::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $ledger->employee_id)->delete());
    }

    public function test_locked_is_whether_locked_at_is_set(): void
    {
        $open = Ledger::factory()->create(['month' => '2026-09-01']);
        $shut = Ledger::factory()->locked()->create(['month' => '2026-10-01']);

        $this->assertFalse($open->locked());
        $this->assertTrue($shut->locked());
    }

    /**
     * ledgers_lock_complete, permitting path. workdays and punches do not
     * exist yet, so the EXISTS is empty (or the function returns before
     * querying them). A row created already locked, and a later UPDATE that
     * sets locked_at, must both succeed — INSERT is a first-class event of
     * this trigger, not an accident of UPDATE OF.
     */
    public function test_a_ledger_with_no_workdays_may_lock(): void
    {
        $created = Ledger::factory()->locked()->create(['month' => '2026-09-01']);

        $this->assertNotNull(DB::table('ledgers')->where('id', $created->id)->value('locked_at'));

        $open = Ledger::factory()->create(['month' => '2026-09-01']);

        DB::table('ledgers')->where('id', $open->id)->update(['locked_at' => '2026-09-11 12:00:00']);

        $this->assertNotNull(DB::table('ledgers')->where('id', $open->id)->value('locked_at'));
    }

    /** ledgers_lock_complete, refusing path. */
    public function test_a_ledger_cannot_lock_while_a_punch_is_still_due(): void
    {
        $this->markTestSkipped('punches arrive in 0001_01_01_000031');

        $ledger = Ledger::factory()->create(['month' => '2026-09-01']);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('ledgers')->where('id', $ledger->id)->update([
            'locked_at' => '2026-09-11 12:00:00',
        ]));
    }

    /** ledgers_unlock_clean, permitting path. */
    public function test_a_ledger_with_no_attestations_may_unlock(): void
    {
        $ledger = Ledger::factory()->locked()->create(['month' => '2026-09-01']);

        DB::table('ledgers')->where('id', $ledger->id)->update(['locked_at' => null]);

        $this->assertNull(DB::table('ledgers')->where('id', $ledger->id)->value('locked_at'));
    }

    /** ledgers_unlock_clean, refusing path. */
    public function test_a_ledger_with_attestations_cannot_unlock(): void
    {
        $this->markTestSkipped('attestations arrive after 0001_01_01_000031');

        $ledger = Ledger::factory()->locked()->create(['month' => '2026-09-01']);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('ledgers')->where('id', $ledger->id)->update([
            'locked_at' => null,
        ]));
    }

    /**
     * deployments_frozen_month. Four writes that change which locked months
     * the range covers (decision 58): insert onto a frozen month, re-date
     * into one, re-date out of one (the OLD limb), and delete one off it.
     */
    public function test_a_write_cannot_change_which_locked_months_a_deployment_covers(): void
    {
        $prior = $this->placementLockedInSeptember([
            'starts' => '2026-01-01',
            'ends' => '2026-08-31',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => Deployment::factory()->create([
            'agency_id' => $prior->agency_id,
            'employee_id' => $prior->employee_id,
            'workgroup_id' => $prior->workgroup_id,
            'starts' => '2026-09-01',
            'ends' => null,
        ]));

        $later = Deployment::factory()->create([
            'agency_id' => $prior->agency_id,
            'employee_id' => $prior->employee_id,
            'workgroup_id' => $prior->workgroup_id,
            'starts' => '2026-10-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')->where('id', $later->id)->update([
            'starts' => '2026-09-15',
        ]));

        $covering = $this->placementLockedInSeptember([
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')->where('id', $covering->id)->update([
            'starts' => '2026-10-01',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')->where('id', $covering->id)->delete());
    }

    /**
     * Decision 58's load-bearing permit: closing an open placement that still
     * covers the locked month must succeed. An overlap rule would refuse
     * TransferEmployee and RemoveEmployee from the first lock, permanently.
     * [2026-01-01, ∞) and [2026-01-01, 2026-10-15] both cover September.
     */
    public function test_closing_an_open_placement_is_allowed_when_it_still_covers_the_locked_month(): void
    {
        $covering = $this->placementLockedInSeptember([
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        DB::table('deployments')->where('id', $covering->id)->update(['ends' => '2026-10-15']);

        $this->assertDatabaseHas('deployments', ['id' => $covering->id, 'ends' => '2026-10-15']);
    }

    /**
     * A range that never covered the locked month may be deleted, and another
     * employee's overlapping range is not this employee's freeze.
     */
    public function test_a_deployment_that_does_not_cover_a_locked_month_may_be_rewritten(): void
    {
        $prior = $this->placementLockedInSeptember([
            'starts' => '2026-01-01',
            'ends' => '2026-08-31',
        ]);

        DB::table('deployments')->where('id', $prior->id)->delete();

        $this->assertDatabaseMissing('deployments', ['id' => $prior->id]);

        $colleague = Employee::factory()->create(['agency_id' => $prior->agency_id]);

        $theirs = Deployment::factory()->create([
            'agency_id' => $prior->agency_id,
            'employee_id' => $colleague->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $this->assertDatabaseHas('deployments', ['id' => $theirs->id]);
    }

    public function test_an_unlocked_ledger_does_not_freeze_deployments(): void
    {
        $deployment = Deployment::factory()->create([
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        Ledger::factory()->create([
            'agency_id' => $deployment->agency_id,
            'employee_id' => $deployment->employee_id,
            'month' => '2026-09-01',
        ]);

        DB::table('deployments')->where('id', $deployment->id)->delete();

        $this->assertDatabaseMissing('deployments', ['id' => $deployment->id]);
    }
}

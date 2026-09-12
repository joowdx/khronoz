<?php

namespace Tests\Feature\Models;

use App\Models\Attestation;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Ledger;
use App\Models\Overtime;
use App\Models\Punch;
use App\Models\Workday;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
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

    public function test_month_must_be_the_first_of_the_month(): void
    {
        $ledger = Ledger::factory()->create();

        $this->assertDatabaseRefuses(
            '23514',
            fn () => DB::table('ledgers')->insert($this->ledgerRow($ledger, ['month' => '2026-09-15'])),
            'ledgers_month_is_first_of_month',
        );
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'ledgers_id_agency_id_unique'"));
    }

    public function test_id_employee_id_and_month_are_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'ledgers_id_employee_id_month_unique'"));
    }

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

    public function test_employee_must_share_the_ledgers_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Ledger::factory()->create(['employee_id' => $employee->id]));
    }

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

    public function test_a_ledger_with_no_workdays_may_lock(): void
    {
        $created = Ledger::factory()->locked()->create(['month' => '2026-09-01']);

        $this->assertNotNull(DB::table('ledgers')->where('id', $created->id)->value('locked_at'));

        $open = Ledger::factory()->create(['month' => '2026-09-01']);

        DB::table('ledgers')->where('id', $open->id)->update(['locked_at' => '2026-09-11 12:00:00']);

        $this->assertNotNull(DB::table('ledgers')->where('id', $open->id)->value('locked_at'));
    }

    public function test_a_ledger_cannot_lock_while_a_punch_is_still_due(): void
    {
        $ledger = Ledger::factory()->create(['month' => '2026-09-01']);
        $workday = Workday::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => '2026-09-15',
        ]);
        Punch::factory()->missed()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'expected_at' => '2026-09-15 08:00:00',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('ledgers')->where('id', $ledger->id)->update([
            'locked_at' => '2026-09-11 12:00:00',
        ]));
    }

    public function test_a_ledger_with_no_attestations_may_unlock(): void
    {
        $ledger = Ledger::factory()->locked()->create(['month' => '2026-09-01']);

        DB::table('ledgers')->where('id', $ledger->id)->update(['locked_at' => null]);

        $this->assertNull(DB::table('ledgers')->where('id', $ledger->id)->value('locked_at'));
    }

    public function test_a_ledger_with_attestations_cannot_unlock(): void
    {
        $ledger = Ledger::factory()->locked()->create(['month' => '2026-09-01']);
        Attestation::factory()->create([
            'agency_id' => $ledger->agency_id,
            'ledger_id' => $ledger->id,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('ledgers')->where('id', $ledger->id)->update([
            'locked_at' => null,
        ]));
    }

    public function test_a_locked_ledger_cannot_be_locked_again(): void
    {
        $ledger = Ledger::factory()->locked()->create(['month' => '2026-09-01']);

        $this->assertDatabaseRefuses(
            'P0001',
            fn () => DB::table('ledgers')->where('id', $ledger->id)->update(['locked_at' => '2026-10-06 12:00:00']),
            'a locked ledger must be unlocked before it can be locked again',
        );
    }

    public function test_a_ledger_may_be_locked_again_after_it_is_unlocked(): void
    {
        $ledger = Ledger::factory()->locked()->create(['month' => '2026-09-01']);

        DB::table('ledgers')->where('id', $ledger->id)->update(['locked_at' => null]);
        DB::table('ledgers')->where('id', $ledger->id)->update(['locked_at' => '2026-10-06 12:00:00']);

        $this->assertDatabaseHas('ledgers', ['id' => $ledger->id, 'locked_at' => '2026-10-06 12:00:00']);
    }

    public function test_rewriting_the_same_lock_instant_is_allowed(): void
    {
        $ledger = Ledger::factory()->locked()->create(['month' => '2026-09-01']);
        $at = DB::table('ledgers')->where('id', $ledger->id)->value('locked_at');

        DB::table('ledgers')->where('id', $ledger->id)->update(['locked_at' => $at]);

        $this->assertDatabaseHas('ledgers', ['id' => $ledger->id, 'locked_at' => $at]);
    }

    /** @return array{0: string, 1: string, 2: Ledger} agency id, employee id, the ledger */
    private function september(): array
    {
        $ledger = Ledger::factory()->create(['month' => '2026-09-01']);

        return [$ledger->agency_id, $ledger->employee_id, $ledger];
    }

    private function lockIt(Ledger $ledger): void
    {
        DB::table('ledgers')->where('id', $ledger->id)->update(['locked_at' => '2026-10-05 12:00:00']);
    }

    public function test_a_write_cannot_change_which_locked_months_an_exemption_covers(): void
    {
        [$agency, $employee, $ledger] = $this->september();

        $straddling = Exemption::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'date' => '2026-08-20',
            'until' => '2026-09-05',
        ]);

        $this->lockIt($ledger);

        $this->assertDatabaseRefuses('P0001', fn () => Exemption::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'date' => '2026-09-10',
            'until' => '2026-09-10',
        ]), 'an exemption cannot change which locked months it covers');

        $october = Exemption::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'date' => '2026-10-05',
            'until' => '2026-10-05',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('exemptions')->where('id', $october->id)->update([
            'date' => '2026-09-20',
            'until' => '2026-09-20',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('exemptions')->where('id', $straddling->id)->update([
            'until' => '2026-08-25',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('exemptions')->where('id', $straddling->id)->delete());
    }

    public function test_an_exemption_outside_every_locked_month_is_writable(): void
    {
        [$agency, $employee, $ledger] = $this->september();
        $this->lockIt($ledger);

        $october = Exemption::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'date' => '2026-10-05',
            'until' => '2026-10-05',
        ]);

        DB::table('exemptions')->where('id', $october->id)->update(['until' => '2026-10-07']);
        $this->assertDatabaseHas('exemptions', ['id' => $october->id, 'until' => '2026-10-07']);

        DB::table('exemptions')->where('id', $october->id)->delete();
        $this->assertDatabaseMissing('exemptions', ['id' => $october->id]);
    }

    public function test_a_write_cannot_change_which_locked_months_an_authority_covers(): void
    {
        [$agency, $employee, $ledger] = $this->september();

        $overnight = Overtime::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'starts' => '2026-09-30 22:00:00',
            'ends' => '2026-10-01 02:00:00',
        ]);

        $this->lockIt($ledger);

        $this->assertDatabaseRefuses('P0001', fn () => Overtime::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'starts' => '2026-09-10 17:00:00',
            'ends' => '2026-09-10 21:00:00',
        ]), 'an overtime authority cannot change which locked months it covers');

        $october = Overtime::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'starts' => '2026-10-05 17:00:00',
            'ends' => '2026-10-05 21:00:00',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('overtimes')->where('id', $october->id)->update([
            'starts' => '2026-09-20 17:00:00',
            'ends' => '2026-09-20 21:00:00',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('overtimes')->where('id', $overnight->id)->update([
            'starts' => '2026-10-01 22:00:00',
            'ends' => '2026-10-02 02:00:00',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('overtimes')->where('id', $overnight->id)->delete());
    }

    public function test_an_authority_ending_inside_a_locked_month_is_refused(): void
    {
        [$agency, $employee, $ledger] = $this->september();
        $this->lockIt($ledger);

        $this->assertDatabaseRefuses('P0001', fn () => Overtime::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'starts' => '2026-08-31 22:00:00',
            'ends' => '2026-09-01 02:00:00',
        ]), 'an overtime authority cannot change which locked months it covers');
    }

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

    public function test_a_placement_cannot_be_shortened_inside_a_locked_month(): void
    {
        $covering = $this->placementLockedInSeptember([
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')->where('id', $covering->id)->update([
            'starts' => '2026-09-15',
        ]));
    }

    public function test_a_placement_cannot_be_lengthened_inside_a_locked_month(): void
    {
        $covering = $this->placementLockedInSeptember([
            'starts' => '2026-09-10',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')->where('id', $covering->id)->update([
            'starts' => '2026-09-01',
        ]));
    }

    public function test_closing_an_open_placement_is_allowed_when_it_still_covers_the_locked_month(): void
    {
        $covering = $this->placementLockedInSeptember([
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        DB::table('deployments')->where('id', $covering->id)->update(['ends' => '2026-10-15']);

        $this->assertDatabaseHas('deployments', ['id' => $covering->id, 'ends' => '2026-10-15']);
    }

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

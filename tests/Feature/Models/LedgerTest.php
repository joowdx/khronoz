<?php

namespace Tests\Feature\Models;

use App\Enums\Work;
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
        return [...$like->getRawOriginal(), 'id' => (string) Str::ulid(), ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $range
     */
    private function placementLockedInAugust(array $range): Deployment
    {
        $deployment = Deployment::factory()->create($range);

        Ledger::factory()->locked()->create([
            'agency_id' => $deployment->agency_id,
            'employee_id' => $deployment->employee_id,
            'starts' => '2026-08-01',
            'ends' => '2026-08-31',
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

    public function test_starts_are_required(): void
    {
        $ledger = Ledger::factory()->create();
        $this->assertDatabaseRefuses('23502', fn () => DB::table('ledgers')->insert($this->ledgerRow($ledger, ['starts' => null])), 'column "starts"');
    }

    public function test_range_end_cannot_precede_its_start(): void
    {
        $ledger = Ledger::factory()->create();
        $this->assertDatabaseRefuses('23514', fn () => DB::table('ledgers')->insert($this->ledgerRow($ledger, ['starts' => '2026-08-02', 'ends' => '2026-08-01'])), 'ledgers_range_valid');
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'ledgers_id_agency_id_unique'"));
    }

    public function test_exact_employee_range_scope_and_revision_are_unique(): void
    {
        $ledger = Ledger::factory()->create();
        $this->assertDatabaseRefuses('23505', fn () => DB::table('ledgers')->insert($this->ledgerRow($ledger)));
    }

    public function test_one_employee_cannot_have_two_active_revisions_for_the_same_range_and_scope(): void
    {
        $ledger = Ledger::factory()->create();
        $this->assertDatabaseRefuses('23505', fn () => DB::table('ledgers')->insert($this->ledgerRow($ledger, ['revision' => 2])), 'ledgers_one_active');
    }

    public function test_one_employee_may_have_ledgers_for_different_months(): void
    {
        $first = Ledger::factory()->create(['starts' => '2026-08-01', 'ends' => '2026-08-31']);

        $second = Ledger::factory()->create([
            'agency_id' => $first->agency_id,
            'employee_id' => $first->employee_id,
            'starts' => '2026-07-01',
            'ends' => '2026-07-31',
        ]);

        $this->assertDatabaseHas('ledgers', ['id' => $second->id]);
    }

    public function test_two_employees_may_have_a_ledger_for_the_same_month(): void
    {
        $first = Ledger::factory()->create(['starts' => '2026-08-01', 'ends' => '2026-08-31']);

        $second = Ledger::factory()->create([
            'agency_id' => $first->agency_id,
            'starts' => '2026-08-01',
            'ends' => '2026-08-31',
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

    public function test_locked_is_a_lock_that_has_not_been_unlocked(): void
    {
        $open = Ledger::factory()->create();
        DB::table('ledgers')->where('id', $open->id)->update(['unlocked_at' => now(), 'unlocked_by' => $open->locked_by]);
        $shut = Ledger::factory()->create();
        $this->assertFalse($open->fresh()->locked());
        $this->assertTrue($shut->locked());
    }

    public function test_a_ledger_with_no_workdays_may_lock(): void
    {
        $created = Ledger::factory()->create();
        $this->assertNotNull($created->locked_at);
        $this->assertDatabaseCount('workdays', 0);
    }

    public function test_a_ledger_cannot_lock_while_an_out_punch_is_still_due(): void
    {
        $workday = Workday::factory()->create(['date' => today()->toDateString()]);
        Punch::factory()->missed()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'kind' => 'out',
            'expected_at' => now()->addDay(),
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => Ledger::factory()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'starts' => today()->toDateString(),
            'ends' => today()->toDateString(),
        ]), 'a ledger cannot lock while a punch is still due');
    }

    public function test_a_ledger_with_no_attestations_may_unlock(): void
    {
        $ledger = Ledger::factory()->create();
        DB::table('ledgers')->where('id', $ledger->id)->update(['unlocked_at' => now(), 'unlocked_by' => $ledger->locked_by]);
        $this->assertNotNull($ledger->fresh()->unlocked_at);
        $this->assertNotNull($ledger->fresh()->locked_at);
    }

    public function test_a_ledger_with_attestations_cannot_unlock(): void
    {
        $ledger = Ledger::factory()->locked()->create(['starts' => '2026-08-01', 'ends' => '2026-08-31']);
        Attestation::factory()->create([
            'agency_id' => $ledger->agency_id,
            'ledger_id' => $ledger->id,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('ledgers')->where('id', $ledger->id)->update([
            'unlocked_at' => now(), 'unlocked_by' => $ledger->locked_by,
        ]));
    }

    public function test_a_locked_ledger_cannot_be_locked_again(): void
    {
        $ledger = Ledger::factory()->locked()->create(['starts' => '2026-08-01', 'ends' => '2026-08-31']);

        $this->assertDatabaseRefuses(
            'P0001',
            fn () => DB::table('ledgers')->where('id', $ledger->id)->update(['locked_at' => '2026-09-06 12:00:00']),
            'a locked ledger snapshot cannot be changed',
        );
    }

    public function test_relocking_after_unlock_requires_a_new_revision(): void
    {
        $ledger = Ledger::factory()->create();
        DB::table('ledgers')->where('id', $ledger->id)->update(['unlocked_at' => now(), 'unlocked_by' => $ledger->locked_by]);
        $next = Ledger::factory()->create(['agency_id' => $ledger->agency_id, 'employee_id' => $ledger->employee_id, 'starts' => $ledger->starts, 'ends' => $ledger->ends, 'revision' => 2]);

        $this->assertNotSame($ledger->id, $next->id);
        $this->assertTrue($next->locked());
        $this->assertFalse($ledger->fresh()->locked());
    }

    public function test_rewriting_the_same_lock_instant_is_allowed(): void
    {
        $ledger = Ledger::factory()->locked()->create(['starts' => '2026-08-01', 'ends' => '2026-08-31']);
        $at = DB::table('ledgers')->where('id', $ledger->id)->value('locked_at');

        DB::table('ledgers')->where('id', $ledger->id)->update(['locked_at' => $at]);

        $this->assertDatabaseHas('ledgers', ['id' => $ledger->id, 'locked_at' => $at]);
    }

    /** @return array{0: string, 1: string, 2: Ledger} agency id, employee id, the ledger */
    private function august(): array
    {
        $ledger = Ledger::factory()->make(['starts' => '2026-08-01', 'ends' => '2026-08-31']);

        return [$ledger->agency_id, $ledger->employee_id, $ledger];
    }

    private function lockIt(Ledger $ledger): void
    {
        $ledger->save();
    }

    public function test_a_write_cannot_change_which_locked_months_an_exemption_covers(): void
    {
        [$agency, $employee, $ledger] = $this->august();

        $straddling = Exemption::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'date' => '2026-07-20',
            'until' => '2026-08-05',
        ]);

        $this->lockIt($ledger);

        $this->assertDatabaseRefuses('P0001', fn () => Exemption::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'date' => '2026-08-10',
            'until' => '2026-08-10',
        ]), 'an exemption cannot change which locked ledger ranges it covers');

        $september = Exemption::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'date' => '2026-09-05',
            'until' => '2026-09-05',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('exemptions')->where('id', $september->id)->update([
            'date' => '2026-08-20',
            'until' => '2026-08-20',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('exemptions')->where('id', $straddling->id)->update([
            'until' => '2026-07-25',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('exemptions')->where('id', $straddling->id)->delete());
    }

    public function test_an_exemption_outside_every_locked_month_is_writable(): void
    {
        [$agency, $employee, $ledger] = $this->august();
        $this->lockIt($ledger);

        $september = Exemption::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'date' => '2026-09-05',
            'until' => '2026-09-05',
        ]);

        DB::table('exemptions')->where('id', $september->id)->update(['until' => '2026-09-07']);
        $this->assertDatabaseHas('exemptions', ['id' => $september->id, 'until' => '2026-09-07']);

        DB::table('exemptions')->where('id', $september->id)->delete();
        $this->assertDatabaseMissing('exemptions', ['id' => $september->id]);
    }

    public function test_a_write_cannot_change_which_locked_months_an_authority_covers(): void
    {
        [$agency, $employee, $ledger] = $this->august();

        $overnight = Overtime::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'starts' => '2026-08-30 22:00:00',
            'ends' => '2026-09-01 02:00:00',
        ]);

        $this->lockIt($ledger);

        $this->assertDatabaseRefuses('P0001', fn () => Overtime::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'starts' => '2026-08-10 17:00:00',
            'ends' => '2026-08-10 21:00:00',
        ]), 'an overtime authority cannot change which locked ledger ranges it covers');

        $september = Overtime::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'starts' => '2026-09-05 17:00:00',
            'ends' => '2026-09-05 21:00:00',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('overtimes')->where('id', $september->id)->update([
            'starts' => '2026-08-20 17:00:00',
            'ends' => '2026-08-20 21:00:00',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('overtimes')->where('id', $overnight->id)->update([
            'starts' => '2026-09-01 22:00:00',
            'ends' => '2026-09-02 02:00:00',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('overtimes')->where('id', $overnight->id)->delete());
    }

    public function test_an_authority_ending_inside_a_locked_month_is_refused(): void
    {
        [$agency, $employee, $ledger] = $this->august();
        $this->lockIt($ledger);

        $this->assertDatabaseRefuses('P0001', fn () => Overtime::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'starts' => '2026-07-31 22:00:00',
            'ends' => '2026-08-01 02:00:00',
        ]), 'an overtime authority cannot change which locked ledger ranges it covers');
    }

    public function test_a_regular_only_ledger_does_not_freeze_overtime_authorities(): void
    {
        [$agency, $employee, $ledger] = $this->august();
        $ledger->scope = Work::Regular;
        $this->lockIt($ledger);

        $authority = Overtime::factory()->create([
            'agency_id' => $agency,
            'employee_id' => $employee,
            'starts' => '2026-08-10 17:00:00',
            'ends' => '2026-08-10 21:00:00',
        ]);

        $authority->update(['ends' => '2026-08-10 22:00:00']);
        $authority->delete();

        $this->assertDatabaseMissing('overtimes', ['id' => $authority->id]);
    }

    public function test_a_write_cannot_change_which_locked_months_a_deployment_covers(): void
    {
        $prior = $this->placementLockedInAugust([
            'starts' => '2026-01-01',
            'ends' => '2026-07-31',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => Deployment::factory()->create([
            'agency_id' => $prior->agency_id,
            'employee_id' => $prior->employee_id,
            'workgroup_id' => $prior->workgroup_id,
            'starts' => '2026-08-01',
            'ends' => null,
        ]));

        $later = Deployment::factory()->create([
            'agency_id' => $prior->agency_id,
            'employee_id' => $prior->employee_id,
            'workgroup_id' => $prior->workgroup_id,
            'starts' => '2026-09-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')->where('id', $later->id)->update([
            'starts' => '2026-08-15',
        ]));

        $covering = $this->placementLockedInAugust([
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')->where('id', $covering->id)->update([
            'starts' => '2026-09-01',
        ]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')->where('id', $covering->id)->delete());
    }

    public function test_a_placement_cannot_be_shortened_inside_a_locked_month(): void
    {
        $covering = $this->placementLockedInAugust([
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')->where('id', $covering->id)->update([
            'starts' => '2026-08-15',
        ]));
    }

    public function test_a_placement_cannot_be_lengthened_inside_a_locked_month(): void
    {
        $covering = $this->placementLockedInAugust([
            'starts' => '2026-08-10',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('deployments')->where('id', $covering->id)->update([
            'starts' => '2026-08-01',
        ]));
    }

    public function test_closing_an_open_placement_is_allowed_when_it_still_covers_the_locked_month(): void
    {
        $covering = $this->placementLockedInAugust([
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        DB::table('deployments')->where('id', $covering->id)->update(['ends' => '2026-09-15']);

        $this->assertDatabaseHas('deployments', ['id' => $covering->id, 'ends' => '2026-09-15']);
    }

    public function test_a_deployment_that_does_not_cover_a_locked_month_may_be_rewritten(): void
    {
        $prior = $this->placementLockedInAugust([
            'starts' => '2026-01-01',
            'ends' => '2026-07-31',
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
        $deployment = Deployment::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $ledger = Ledger::factory()->create(['agency_id' => $deployment->agency_id, 'employee_id' => $deployment->employee_id, 'starts' => '2026-08-01', 'ends' => '2026-08-31']);
        DB::table('ledgers')->where('id', $ledger->id)->update(['unlocked_at' => now(), 'unlocked_by' => $ledger->locked_by]);

        DB::table('deployments')->where('id', $deployment->id)->delete();

        $this->assertDatabaseMissing('deployments', ['id' => $deployment->id]);
    }
}

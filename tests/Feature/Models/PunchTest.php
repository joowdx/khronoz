<?php

namespace Tests\Feature\Models;

use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Ledger;
use App\Models\Punch;
use App\Models\Timelog;
use App\Models\User;
use App\Models\Workday;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PunchTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function punchRow(Punch $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'workday_id' => $like->workday_id,
            'employee_id' => $like->employee_id,
            'slot' => 2,
            'kind' => 'out',
            'expected_at' => '2026-09-15 17:00:00',
            'timelog_id' => null,
            'actual_at' => null,
            'deviation' => null,
            'created_at' => now(),
            ...$overrides,
        ];
    }

    public function test_punch_needs_an_agency(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['agency_id' => null])
        ));
    }

    public function test_workday_is_required(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['workday_id' => null])
        ));
    }

    public function test_employee_is_required(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['employee_id' => null])
        ));
    }

    public function test_slot_is_required(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['slot' => null])
        ));
    }

    public function test_kind_is_required(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['kind' => null])
        ));
    }

    public function test_a_punch_must_hold_an_expectation_or_an_arrival(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, [
                'expected_at' => null,
                'timelog_id' => null,
                'actual_at' => null,
                'deviation' => null,
            ])
        ), 'punches_records_a_time');
    }

    public function test_a_tap_with_no_expectation_is_accepted(): void
    {
        $punch = Punch::factory()->create();
        $spare = $this->resolvedTimelog($punch->agency_id, $punch->employee_id);

        DB::table('punches')->insert($this->punchRow($punch, [
            'expected_at' => null,
            'timelog_id' => $spare->id,
            'actual_at' => '2026-09-15 17:01:00',
            'deviation' => null,
        ]));

        $this->assertDatabaseHas('punches', ['timelog_id' => $spare->id, 'expected_at' => null]);
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'punches_id_agency_id_unique'"));
    }

    public function test_one_slot_side_cannot_be_punched_twice(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses(
            '23505',
            fn () => DB::table('punches')->insert($this->punchRow($punch, [
                'slot' => $punch->slot,
                'kind' => $punch->kind->value,
            ])),
            'punches_workday_id_slot_kind_unique',
        );
    }

    public function test_two_slot_sides_of_one_workday_may_exist(): void
    {
        $in = Punch::factory()->create();

        $out = Punch::factory()->missed()->create([
            'agency_id' => $in->agency_id,
            'employee_id' => $in->employee_id,
            'workday_id' => $in->workday_id,
            'slot' => 1,
            'kind' => 'out',
            'expected_at' => '2026-09-15 12:00:00',
        ]);

        $this->assertDatabaseHas('punches', ['id' => $out->id]);
    }

    public function test_one_timelog_cannot_fill_two_punches(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-09-15']);
        $first = Punch::factory()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
        ]);
        $otherDay = Workday::factory()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'date' => '2026-09-16',
        ]);

        $this->assertDatabaseRefuses('23505', fn () => DB::table('punches')->insert(
            $this->punchRow($first, [
                'workday_id' => $otherDay->id,
                'timelog_id' => $first->timelog_id,
                'actual_at' => '2026-09-16 08:01:00',
                'deviation' => 1,
            ])
        ));
    }

    public function test_missed_punches_may_share_a_null_timelog(): void
    {
        $workday = Workday::factory()->create();

        Punch::factory()->missed()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'slot' => 1,
            'kind' => 'in',
        ]);
        Punch::factory()->missed()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'slot' => 1,
            'kind' => 'out',
            'expected_at' => '2026-09-15 12:00:00',
        ]);

        $this->assertSame(2, DB::table('punches')->where('workday_id', $workday->id)->whereNull('timelog_id')->count());

        $this->assertStringContainsString(
            'WHERE (timelog_id IS NOT NULL)',
            DB::selectOne("select indexdef from pg_indexes where indexname = 'punches_timelog'")->indexdef,
        );
    }

    public function test_kind_must_be_in_or_out(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['kind' => 'break'])
        ), 'punches_kind_valid');
    }

    public function test_slot_must_be_positive(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['slot' => 0])
        ), 'punches_slot_positive');
        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['slot' => -1])
        ), 'punches_slot_positive');
    }

    public function test_actual_at_and_timelog_id_are_both_set_or_both_null(): void
    {
        $punch = Punch::factory()->create();
        $spare = $this->resolvedTimelog($punch->agency_id, $punch->employee_id);

        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['timelog_id' => $spare->id, 'actual_at' => null, 'deviation' => null])
        ), 'punches_actual_pairs_timelog');
        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['timelog_id' => null, 'actual_at' => '2026-09-15 17:01:00', 'deviation' => 1])
        ), 'punches_actual_pairs_timelog');
    }

    public function test_deviation_needs_both_an_expectation_and_an_arrival(): void
    {
        $punch = Punch::factory()->create();
        $spare = $this->resolvedTimelog($punch->agency_id, $punch->employee_id);

        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, [
                'timelog_id' => $spare->id,
                'actual_at' => '2026-09-15 17:01:00',
                'deviation' => null,
            ])
        ), 'punches_deviation_pairs_both');
        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['timelog_id' => null, 'actual_at' => null, 'deviation' => 5])
        ), 'punches_deviation_pairs_both');
        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, [
                'expected_at' => null,
                'timelog_id' => $spare->id,
                'actual_at' => '2026-09-15 17:01:00',
                'deviation' => 1,
            ])
        ), 'punches_deviation_pairs_both');
    }

    public function test_employee_must_be_the_workdays_employee(): void
    {
        $workday = Workday::factory()->create();
        $other = Employee::factory()->create(['agency_id' => $workday->agency_id]);

        $this->assertDatabaseRefuses('23503', fn () => Punch::factory()->missed()->create([
            'agency_id' => $workday->agency_id,
            'workday_id' => $workday->id,
            'employee_id' => $other->id,
        ]));
    }

    public function test_deleting_a_workday_deletes_its_punches(): void
    {
        $punch = Punch::factory()->missed()->create();

        DB::table('workdays')->where('id', $punch->workday_id)->delete();

        $this->assertDatabaseMissing('punches', ['id' => $punch->id]);
    }

    public function test_a_workday_with_punches_cannot_change_its_id(): void
    {
        $punch = Punch::factory()->missed()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('workdays')->where('id', $punch->workday_id)->update([
            'id' => (string) Str::ulid(),
        ]));
    }

    public function test_an_unresolved_timelog_cannot_fill_a_punch(): void
    {
        $workday = Workday::factory()->create();
        $unresolved = Timelog::factory()->create(['agency_id' => $workday->agency_id]);

        $this->assertNull($unresolved->fresh()->employee_id);

        $this->assertDatabaseRefuses('23503', fn () => Punch::factory()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'timelog_id' => $unresolved->id,
            'actual_at' => '2026-09-15 08:01:00',
            'deviation' => 1,
        ]));
    }

    public function test_a_punch_cannot_use_another_employees_timelog(): void
    {
        $workday = Workday::factory()->create();
        $other = Employee::factory()->create(['agency_id' => $workday->agency_id]);
        $timelog = $this->resolvedTimelog($other->agency_id, $other->id);

        $this->assertDatabaseRefuses('23503', fn () => Punch::factory()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'timelog_id' => $timelog->id,
            'actual_at' => '2026-09-15 08:01:00',
            'deviation' => 1,
        ]));
    }

    public function test_a_punch_cannot_use_a_voided_timelog(): void
    {
        $workday = Workday::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
        ]);
        $timelog = Timelog::factory()->resolving($enrollment)->create();
        $actor = User::factory()->create(['agency_id' => $workday->agency_id]);

        $timelog->void('Duplicate scan', $actor);

        $this->assertDatabaseRefuses('P0001', fn () => Punch::factory()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'timelog_id' => $timelog->id,
            'actual_at' => '2026-09-15 08:01:00',
            'deviation' => 1,
        ]));
    }

    public function test_missed_is_whether_no_timelog_filled_the_slot(): void
    {
        $filled = Punch::factory()->create();
        $missed = Punch::factory()->missed()->create();

        $this->assertFalse($filled->missed());
        $this->assertTrue($missed->missed());
    }

    private function resolvedTimelog(string $agencyId, string $employeeId): Timelog
    {
        $enrollment = Enrollment::factory()->create([
            'agency_id' => $agencyId,
            'employee_id' => $employeeId,
        ]);

        return Timelog::factory()->resolving($enrollment)->create();
    }

    public function test_a_punch_cannot_be_inserted_on_a_locked_ledger(): void
    {
        $punch = $this->punchForLock();

        $this->lock($punch);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('punches')->insert(
            $this->punchRow($punch)
        ), 'a punch cannot be written against a locked ledger');
    }

    public function test_a_punch_cannot_be_updated_on_a_locked_ledger(): void
    {
        $punch = $this->punchForLock();

        $this->lock($punch);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('punches')->where('id', $punch->id)->update([
            'actual_at' => '2026-09-15 07:59:00',
            'deviation' => -1,
        ]));
    }

    public function test_a_punch_cannot_be_deleted_on_a_locked_ledger(): void
    {
        $punch = $this->punchForLock();

        $this->lock($punch);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('punches')->where('id', $punch->id)->delete());
    }

    public function test_a_punch_cannot_be_moved_out_of_a_locked_ledger(): void
    {
        $punch = $this->punchForLock();
        $open = Workday::factory()->create([
            'agency_id' => $punch->agency_id,
            'employee_id' => $punch->employee_id,
            'date' => '2026-10-01',
        ]);

        $this->lock($punch);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('punches')->where('id', $punch->id)->update([
            'workday_id' => $open->id,
        ]));
    }

    public function test_punch_writes_succeed_once_the_ledger_is_unlocked(): void
    {
        $punch = $this->punchForLock();

        $ledger = $this->lock($punch);
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('punches')->where('id', $punch->id)->delete());

        DB::table('ledgers')->where('id', $ledger->id)->update(['unlocked_at' => now(), 'unlocked_by' => $ledger->locked_by]);

        DB::table('punches')->where('id', $punch->id)->delete();
        $this->assertDatabaseMissing('punches', ['id' => $punch->id]);

        $id = (string) Str::ulid();
        DB::table('punches')->insert($this->punchRow($punch, ['id' => $id]));
        $this->assertDatabaseHas('punches', ['id' => $id]);
    }

    public function test_a_transit_does_not_hold_a_ledger_open(): void
    {
        $punch = $this->punchForLock();
        DB::table('punches')->where('id', $punch->id)->update([
            'expected_at' => null,
            'deviation' => null,
        ]);

        $ledger = $this->lock($punch);

        $this->assertTrue($ledger->locked());
    }

    private function lock(Punch $punch): Ledger
    {
        return Ledger::factory()->create(['agency_id' => $punch->agency_id, 'employee_id' => $punch->employee_id, 'starts' => '2026-08-01', 'ends' => '2026-08-31']);
    }

    private function punchForLock(): Punch
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        return Punch::factory()->create(['agency_id' => $workday->agency_id, 'employee_id' => $workday->employee_id, 'workday_id' => $workday->id]);
    }
}

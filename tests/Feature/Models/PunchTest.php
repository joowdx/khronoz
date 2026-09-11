<?php

namespace Tests\Feature\Models;

use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Punch;
use App\Models\Timelog;
use App\Models\User;
use App\Models\Workday;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One test per constraint and trigger on `punches` (docs/design/07-constraints.md).
 *
 * No agency_not_platform test: a punch needs a workday, and a workday needs
 * a ledger, and employees refuse the platform agency already.
 *
 * The row helper is a missed punch on slot 2 out, so it does not collide
 * with the factory default (slot 1 in, filled) on either UNIQUE
 * (workday_id, slot, kind) or punches_timelog.
 */
class PunchTest extends TestCase
{
    /** @return array<string, mixed> */
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

    /**
     * employee_id NOT NULL, and one nullable column would defeat both
     * composite FKs: MATCH SIMPLE skips them entirely once a referencing
     * column is null.
     */
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

    public function test_expected_at_is_required(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['expected_at' => null])
        ));
    }

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'punches_id_agency_id_unique'"));
    }

    /** punches_workday_id_slot_kind_unique. */
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

    /**
     * punches_timelog. One timelog fills one slot side, ever. The colliding
     * insert is a different workday so (workday_id, slot, kind) is not the
     * constraint doing the work.
     */
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
            'ledger_id' => $workday->ledger_id,
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

    /**
     * The reason that index is **partial**. A missed punch has no timelog, and
     * an unlimited number of those may exist. NULLS DISTINCT would permit the
     * same inserts, so the predicate is read off the catalog the way
     * terminals_serial is.
     */
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

    /** punches_kind_valid. EnumCheckContractTest holds the list to the enum. */
    public function test_kind_must_be_in_or_out(): void
    {
        $punch = Punch::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['kind' => 'break'])
        ), 'punches_kind_valid');
    }

    /** punches_slot_positive. */
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

    /** punches_actual_pairs_timelog. A fresh timelog so punches_timelog is not the refusal. */
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

    /** punches_deviation_pairs_actual. Isolated from actual_pairs_timelog by setting both timelog and actual. */
    public function test_deviation_and_actual_at_are_both_set_or_both_null(): void
    {
        $punch = Punch::factory()->create();
        $spare = $this->resolvedTimelog($punch->agency_id, $punch->employee_id);

        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, [
                'timelog_id' => $spare->id,
                'actual_at' => '2026-09-15 17:01:00',
                'deviation' => null,
            ])
        ), 'punches_deviation_pairs_actual');
        $this->assertDatabaseRefuses('23514', fn () => DB::table('punches')->insert(
            $this->punchRow($punch, ['timelog_id' => null, 'actual_at' => null, 'deviation' => 5])
        ), 'punches_deviation_pairs_actual');
    }

    /** punches_workday_id_employee_id_foreign, insert side. */
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

    /**
     * ON DELETE CASCADE: punches are derived rows with no independent
     * existence. This is the one place in the schema that cascades.
     */
    public function test_deleting_a_workday_deletes_its_punches(): void
    {
        $punch = Punch::factory()->missed()->create();

        DB::table('workdays')->where('id', $punch->workday_id)->delete();

        $this->assertDatabaseMissing('punches', ['id' => $punch->id]);
    }

    /** Same FK, update side stays RESTRICT. */
    public function test_a_workday_with_punches_cannot_change_its_id(): void
    {
        $punch = Punch::factory()->missed()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('workdays')->where('id', $punch->workday_id)->update([
            'id' => (string) Str::ulid(),
        ]));
    }

    /**
     * The composite FK (timelog_id, employee_id) is load-bearing beyond
     * existence. An unresolved timelog has employee_id null, so it can never
     * match a punch's non-null employee_id — a punch can only ever use a
     * resolved timelog, structurally.
     */
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

    /** Same FK: a resolved timelog of somebody else. No punch claims it, so punches_timelog is not the refusal. */
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

    /**
     * punches_timelog_live. The signature takes an actor since decision 48.
     * INSERT only: a punch that already claimed a record survives a later
     * void (decision 57).
     */
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
}

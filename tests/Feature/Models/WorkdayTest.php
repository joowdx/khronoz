<?php

namespace Tests\Feature\Models;

use App\Attendance\Day;
use App\Attendance\Snapshot;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Ledger;
use App\Models\Shift;
use App\Models\Workday;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One test per constraint on `workdays` (docs/design/07-constraints.md).
 *
 * No agency_not_platform test and no such trigger: a workday needs a ledger,
 * and a ledger needs an employee, and `employees` refuses the platform agency
 * already.
 *
 * `month` never appears in the row helper below — it is generated, and
 * Postgres refuses any supplied value for a generated column. That refusal
 * has its own test.
 *
 * workdays_agency_id_foreign's delete side is untested for the reason Ruling
 * P5 gives on deployments: deleting the agency trips employees and ledgers
 * first. Its insert side is reachable because this table's ledger FK does
 * not pair agency_id.
 */
class WorkdayTest extends TestCase
{
    /** @return array<string, mixed> */
    private function workdayRow(Workday $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'ledger_id' => $like->ledger_id,
            'employee_id' => $like->employee_id,
            // Same month as the factory default so the ledger FK holds, a
            // different day so (employee_id, date) does not collide.
            'date' => '2026-09-20',
            'shift_id' => $like->shift_id,
            'shift' => $like->shift === null ? null : json_encode($like->shift),
            'exemption_id' => $like->exemption_id,
            'status' => 'present',
            'premium' => null,
            'worked' => 0,
            'credited' => 0,
            'tardy' => 0,
            'undertime' => 0,
            'excess' => 0,
            'night' => 0,
            'night_excess' => 0,
            'computed_at' => '2026-09-15 18:00:00',
            'created_at' => now(),
            ...$overrides,
        ];
    }

    public function test_workday_needs_an_agency(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['agency_id' => null, 'shift_id' => null])
        ));
    }

    public function test_ledger_is_required(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['ledger_id' => null])
        ));
    }

    /**
     * employee_id NOT NULL, and one nullable column would defeat the ledger
     * FK too: MATCH SIMPLE skips a composite FK entirely once a referencing
     * column is null.
     */
    public function test_employee_is_required(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['employee_id' => null])
        ));
    }

    /**
     * `date` NOT NULL, and the refusing **column** is named here because the
     * SQLSTATE alone cannot cover this rule — `month` is generated from
     * `date` and is itself NOT NULL, so a null `date` produces a 23502 either
     * way. The column and not the constraint name because a not-null message
     * names only the column.
     */
    public function test_date_is_required(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses(
            '23502',
            fn () => DB::table('workdays')->insert($this->workdayRow($workday, ['date' => null])),
            'column "date"',
        );
    }

    public function test_status_is_required(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['status' => null])
        ));
    }

    public function test_computed_at_is_required(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['computed_at' => null])
        ));
    }

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'workdays_id_agency_id_unique'"));
    }

    /** The target punches pair against, so a punch is provably that employee's own workday. */
    public function test_id_and_employee_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'workdays_id_employee_id_unique'"));
    }

    /** workdays_employee_id_date_unique. */
    public function test_one_employee_cannot_have_two_workdays_on_the_same_date(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses(
            '23505',
            fn () => DB::table('workdays')->insert($this->workdayRow($workday, [
                'date' => $workday->date->toDateString(),
            ])),
            'workdays_employee_id_date_unique',
        );
    }

    public function test_one_employee_may_have_workdays_on_different_dates(): void
    {
        $first = Workday::factory()->create(['date' => '2026-09-15']);

        $second = Workday::factory()->create([
            'agency_id' => $first->agency_id,
            'employee_id' => $first->employee_id,
            'ledger_id' => $first->ledger_id,
            'date' => '2026-09-16',
        ]);

        $this->assertDatabaseHas('workdays', ['id' => $second->id]);
    }

    public function test_two_employees_may_have_a_workday_on_the_same_date(): void
    {
        $first = Workday::factory()->create(['date' => '2026-09-15']);

        $second = Workday::factory()->create([
            'agency_id' => $first->agency_id,
            'date' => '2026-09-15',
        ]);

        $this->assertDatabaseHas('workdays', ['id' => $second->id]);
        $this->assertNotSame($first->employee_id, $second->employee_id);
    }

    /** workdays_ledger_id_employee_id_month_foreign, insert side: right employee. */
    public function test_employee_must_be_the_ledgers_employee(): void
    {
        $workday = Workday::factory()->create();
        $other = Employee::factory()->create(['agency_id' => $workday->agency_id]);

        $this->assertDatabaseRefuses('23503', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['employee_id' => $other->id])
        ));
    }

    /** Same FK: right month. A October date against a September ledger. */
    public function test_the_workday_must_fall_in_the_ledgers_month(): void
    {
        $ledger = Ledger::factory()->create(['month' => '2026-09-01']);

        $this->assertDatabaseRefuses('23503', fn () => Workday::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => '2026-10-01',
        ]));
    }

    /** Same FK, delete side. */
    public function test_ledger_with_a_workday_cannot_be_deleted(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('ledgers')->where('id', $workday->ledger_id)->delete());
    }

    /** workdays_shift_id_agency_id_foreign, insert side. */
    public function test_shift_must_share_the_workdays_agency(): void
    {
        $workday = Workday::factory()->create();
        $shift = Shift::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['shift_id' => $shift->id])
        ));
    }

    /** Same FK, delete side. */
    public function test_shift_used_by_a_workday_cannot_be_deleted(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('shifts')->where('id', $workday->shift_id)->delete());
    }

    /** workdays_exemption_id_employee_id_foreign, insert side. */
    public function test_exemption_must_belong_to_the_same_employee(): void
    {
        $workday = Workday::factory()->create();
        $exemption = Exemption::factory()->create(['agency_id' => $workday->agency_id]);

        $this->assertDatabaseRefuses('23503', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['exemption_id' => $exemption->id])
        ));
    }

    /** Same FK, delete side. */
    public function test_exemption_stamped_on_a_workday_cannot_be_deleted(): void
    {
        $workday = Workday::factory()->create();
        $exemption = Exemption::factory()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
        ]);

        DB::table('workdays')->where('id', $workday->id)->update(['exemption_id' => $exemption->id]);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('exemptions')->where('id', $exemption->id)->delete());
    }

    /** workdays_agency_id_foreign, insert side. Isolated by leaving shift_id null. */
    public function test_agency_must_exist(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['agency_id' => (string) Str::ulid(), 'shift_id' => null])
        ));
    }

    /** workdays_status_valid. EnumCheckContractTest holds the list to the enum. */
    public function test_status_must_be_a_known_value(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['status' => 'late'])
        ), 'workdays_status_valid');
    }

    /** workdays_premium_valid. Null is an ordinary day (decision 51), and is not a case of Premium. */
    public function test_premium_must_be_rest_special_regular_or_null(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['premium' => 'overtime'])
        ), 'workdays_premium_valid');

        $accepted = DB::table('workdays')->insert($this->workdayRow($workday, ['premium' => null]));
        $this->assertTrue($accepted);
    }

    /**
     * workdays_credited_needs_premium holds in one direction only: a premium
     * day on which nobody worked is ordinary and carries credited = 0, so the
     * converse would refuse the common case.
     */
    public function test_credited_minutes_require_a_premium(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['credited' => 480, 'premium' => null])
        ), 'workdays_credited_needs_premium');

        $id = (string) Str::ulid();
        DB::table('workdays')->insert($this->workdayRow($workday, [
            'id' => $id,
            'credited' => 0,
            'premium' => 'rest',
        ]));
        $this->assertDatabaseHas('workdays', ['id' => $id, 'credited' => 0, 'premium' => 'rest']);
    }

    /** workdays_minutes_not_negative. Premium is set so a negative credited cannot be refused by credited_needs_premium instead. */
    public function test_minute_columns_cannot_be_negative(): void
    {
        $workday = Workday::factory()->create();

        foreach (['worked', 'credited', 'tardy', 'undertime', 'excess', 'night', 'night_excess'] as $column) {
            $this->assertDatabaseRefuses(
                '23514',
                fn () => DB::table('workdays')->insert($this->workdayRow($workday, [
                    $column => -1,
                    'premium' => 'rest',
                ])),
                'workdays_minutes_not_negative',
            );
        }
    }

    /** workdays_shift_is_object. */
    public function test_shift_snapshot_must_be_a_json_object(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['shift' => '[]'])
        ), 'workdays_shift_is_object');

        $id = (string) Str::ulid();
        DB::table('workdays')->insert($this->workdayRow($workday, ['id' => $id, 'shift' => '{}']));
        $this->assertDatabaseHas('workdays', ['id' => $id]);
    }

    /**
     * The generated column. `month` is the first of `date`'s month, is STORED
     * rather than Postgres 18's VIRTUAL default, and a supplied value is
     * refused outright.
     */
    public function test_the_month_is_generated_from_the_date(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-09-15']);

        $this->assertSame('2026-09-01', $workday->month->toDateString());
    }

    /**
     * **STORED, not VIRTUAL.** Postgres 18 defaults a generated column to
     * VIRTUAL, and a virtual column can be neither indexed nor referenced by a
     * foreign key — which is the entire reason this column exists rather than
     * being derived at read time. Blueprint's storedAs() spells it out, and
     * nothing in the migration's own text would reveal a regression to
     * virtualAs(), so the catalog is asserted directly.
     */
    public function test_the_generated_month_is_stored_and_not_virtual(): void
    {
        $column = DB::selectOne("select attgenerated from pg_attribute where attrelid = 'workdays'::regclass and attname = 'month'");

        $this->assertSame('s', $column->attgenerated, "expected STORED ('s'), got ".var_export($column->attgenerated, true));
    }

    /** A generated column refuses a supplied value outright. */
    public function test_the_month_cannot_be_written(): void
    {
        $workday = Workday::factory()->create();

        $this->assertDatabaseRefuses('428C9', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['month' => '2026-09-01'])
        ));
    }

    /**
     * The column `shift` is the snapshot. A relation named `shift()` would
     * make Eloquent resolve `$workday->shift` to the related model instead,
     * silently, and the snapshot is the whole reason the column exists.
     */
    public function test_the_shift_attribute_is_the_snapshot_not_the_related_model(): void
    {
        $workday = Workday::factory()->create();

        $this->assertIsArray($workday->shift);
        $this->assertSame('Standard', $workday->shift['shift']['name']);
        $this->assertArrayHasKey('slots', $workday->shift['shift']);
    }

    /**
     * A factory that invents a json shape is a test that proves nothing
     * (.ai/rules/factories.md). Keys are read off Snapshot::of() itself —
     * a hand-written list is a third shape that drifts with the other two.
     */
    public function test_the_factory_snapshot_matches_what_the_orchestrator_writes(): void
    {
        $workday = Workday::factory()->create();
        $this->withTenant($workday->agency);

        $canonical = Snapshot::of(
            new Day(
                date: CarbonImmutable::parse($workday->date->toDateString()),
                shift: $workday->resolvedShift,
                sides: [],
                status: null,
                premium: null,
                excused: [],
                travel: false,
                exemptionId: null,
                nightFrom: '18:00',
            ),
            new Settings($workday->agency),
            [],
            [],
        );
        $fabricated = $workday->shift;

        // jsonb does not preserve key order; the contract is the set of keys.
        $keys = fn (array $value): array => collect(array_keys($value))->sort()->values()->all();

        $this->assertSame($keys($canonical), $keys($fabricated));
        $this->assertSame($keys($canonical['shift']), $keys($fabricated['shift']));
        $this->assertSame($keys($canonical['settings']), $keys($fabricated['settings']));
    }

    /**
     * workdays_ledger_open, insert side (decision 70). The ledger is created
     * unlocked (WorkdayFactory's default) then locked, so the factory insert
     * itself is not the refusal.
     */
    public function test_a_workday_cannot_be_inserted_on_a_locked_ledger(): void
    {
        $workday = Workday::factory()->create();

        DB::table('ledgers')->where('id', $workday->ledger_id)->update([
            'locked_at' => '2026-09-11 12:00:00',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday)
        ));
    }

    /** workdays_ledger_open, update side. */
    public function test_a_workday_cannot_be_updated_on_a_locked_ledger(): void
    {
        $workday = Workday::factory()->create();

        DB::table('ledgers')->where('id', $workday->ledger_id)->update([
            'locked_at' => '2026-09-11 12:00:00',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->where('id', $workday->id)->update([
            'status' => 'absent',
        ]));
    }

    /** workdays_ledger_open, delete side. Reads OLD: NEW is unassigned. */
    public function test_a_workday_cannot_be_deleted_on_a_locked_ledger(): void
    {
        $workday = Workday::factory()->create();

        DB::table('ledgers')->where('id', $workday->ledger_id)->update([
            'locked_at' => '2026-09-11 12:00:00',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->where('id', $workday->id)->delete());
    }

    /**
     * workdays_ledger_open, the branch that reads OLD on an UPDATE.
     *
     * Moving a workday **out** of a locked month is as much a rewrite of
     * signed history as moving one in, and the NEW check cannot see it: the
     * destination ledger is open, so only OLD's is locked. Reachable because
     * `ledgers` is UNIQUE (employee_id, month) — there is no second ledger for
     * one employee-month, so a move is always a re-dating, and re-dating 30
     * September to 1 October carries the row from September's ledger to
     * October's in one statement.
     *
     * Found by mutation: deleting the OLD branch left the whole suite green,
     * because every other lock test keeps the row in its own ledger.
     */
    public function test_a_workday_cannot_be_re_dated_out_of_a_locked_ledger(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-09-30']);

        $october = DB::table('ledgers')->insertGetId([
            'id' => (string) Str::ulid(),
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'month' => '2026-10-01',
            'locked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        DB::table('ledgers')->where('id', $workday->ledger_id)->update([
            'locked_at' => '2026-10-05 12:00:00',
        ]);

        // September is locked, October is open. Without the OLD lookup the
        // NEW check passes and the day leaves a signed month in silence.
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->where('id', $workday->id)->update([
            'date' => '2026-10-01',
            'ledger_id' => $october,
        ]));
    }

    /**
     * workdays_ledger_open, permitting path. Lock, prove a refusal, then
     * clear locked_at and prove insert, update and delete all succeed — the
     * legitimate unlock-then-recompute sequence, and proof the trigger
     * reads current state rather than something cached at insert.
     */
    public function test_workday_writes_succeed_once_the_ledger_is_unlocked(): void
    {
        $workday = Workday::factory()->create();

        DB::table('ledgers')->where('id', $workday->ledger_id)->update([
            'locked_at' => '2026-09-11 12:00:00',
        ]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->where('id', $workday->id)->update([
            'status' => 'absent',
        ]));

        DB::table('ledgers')->where('id', $workday->ledger_id)->update(['locked_at' => null]);

        DB::table('workdays')->where('id', $workday->id)->update(['status' => 'absent']);
        $this->assertDatabaseHas('workdays', ['id' => $workday->id, 'status' => 'absent']);

        $id = (string) Str::ulid();
        DB::table('workdays')->insert($this->workdayRow($workday, ['id' => $id]));
        $this->assertDatabaseHas('workdays', ['id' => $id]);

        DB::table('workdays')->where('id', $id)->delete();
        $this->assertDatabaseMissing('workdays', ['id' => $id]);
    }
}

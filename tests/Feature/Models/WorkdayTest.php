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

class WorkdayTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function workdayRow(Workday $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'employee_id' => $like->employee_id,
            'date' => '2026-08-20',
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
            'computed_at' => '2026-08-15 18:00:00',
            'created_at' => now(),
            ...$overrides,
        ];
    }

    public function test_workday_needs_an_agency(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertDatabaseRefuses('23502', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['agency_id' => null, 'shift_id' => null])
        ));
    }

    public function test_workdays_exist_without_a_ledger(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);
        $this->assertModelExists($workday);
        $this->assertDatabaseCount('ledgers', 0);
    }

    public function test_employee_is_required(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertDatabaseRefuses('23502', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['employee_id' => null])
        ));
    }

    public function test_date_is_required(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertDatabaseRefuses(
            '23502',
            fn () => DB::table('workdays')->insert($this->workdayRow($workday, ['date' => null])),
            'column "date"',
        );
    }

    public function test_status_is_required(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertDatabaseRefuses('23502', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['status' => null])
        ));
    }

    public function test_computed_at_is_required(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertDatabaseRefuses('23502', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['computed_at' => null])
        ));
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'workdays_id_agency_id_unique'"));
    }

    public function test_id_and_employee_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'workdays_id_employee_id_unique'"));
    }

    public function test_one_employee_cannot_have_two_workdays_on_the_same_date(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

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
        $first = Workday::factory()->create(['date' => '2026-08-15']);

        $second = Workday::factory()->create([
            'agency_id' => $first->agency_id,
            'employee_id' => $first->employee_id,
            'date' => '2026-08-16',
        ]);

        $this->assertDatabaseHas('workdays', ['id' => $second->id]);
    }

    public function test_two_employees_may_have_a_workday_on_the_same_date(): void
    {
        $first = Workday::factory()->create(['date' => '2026-08-15']);

        $second = Workday::factory()->create([
            'agency_id' => $first->agency_id,
            'date' => '2026-08-15',
        ]);

        $this->assertDatabaseHas('workdays', ['id' => $second->id]);
        $this->assertNotSame($first->employee_id, $second->employee_id);
    }

    public function test_employee_must_share_the_workdays_agency(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);
        $other = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['employee_id' => $other->id])
        ));
    }

    public function test_workdays_outside_a_locked_range_remain_writable(): void
    {
        $ledger = Ledger::factory()->create(['starts' => '2026-08-01', 'ends' => '2026-08-15']);
        $workday = Workday::factory()->create(['agency_id' => $ledger->agency_id, 'employee_id' => $ledger->employee_id, 'date' => '2026-08-16']);

        $workday->update(['status' => 'absent']);

        $this->assertDatabaseHas('workdays', ['id' => $workday->id, 'status' => 'absent']);
    }

    public function test_employee_with_a_workday_cannot_be_deleted(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);
        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $workday->employee_id)->delete());
    }

    public function test_shift_must_share_the_workdays_agency(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);
        $shift = Shift::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['shift_id' => $shift->id])
        ));
    }

    public function test_shift_used_by_a_workday_cannot_be_deleted(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('shifts')->where('id', $workday->shift_id)->delete());
    }

    public function test_exemption_must_belong_to_the_same_employee(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);
        $exemption = Exemption::factory()->create(['agency_id' => $workday->agency_id]);

        $this->assertDatabaseRefuses('23503', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['exemption_id' => $exemption->id])
        ));
    }

    public function test_exemption_stamped_on_a_workday_cannot_be_deleted(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);
        $exemption = Exemption::factory()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
        ]);

        DB::table('workdays')->where('id', $workday->id)->update(['exemption_id' => $exemption->id]);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('exemptions')->where('id', $exemption->id)->delete());
    }

    public function test_agency_must_exist(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertDatabaseRefuses('23503', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['agency_id' => (string) Str::ulid(), 'shift_id' => null])
        ));
    }

    public function test_status_must_be_a_known_value(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertDatabaseRefuses('23514', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['status' => 'late'])
        ), 'workdays_status_valid');
    }

    public function test_premium_must_be_rest_special_regular_or_null(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertDatabaseRefuses('23514', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['premium' => 'overtime'])
        ), 'workdays_premium_valid');

        $accepted = DB::table('workdays')->insert($this->workdayRow($workday, ['premium' => null]));
        $this->assertTrue($accepted);
    }

    public function test_credited_minutes_require_a_premium(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

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

    public function test_minute_columns_cannot_be_negative(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

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

    public function test_shift_snapshot_must_be_a_json_object(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertDatabaseRefuses('23514', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday, ['shift' => '[]'])
        ), 'workdays_shift_is_object');

        $id = (string) Str::ulid();
        DB::table('workdays')->insert($this->workdayRow($workday, ['id' => $id, 'shift' => '{}']));
        $this->assertDatabaseHas('workdays', ['id' => $id]);
    }

    public function test_range_query_includes_both_boundary_dates(): void
    {
        $first = Workday::factory()->create(['date' => '2026-08-15']);
        $last = Workday::factory()->create(['agency_id' => $first->agency_id, 'employee_id' => $first->employee_id, 'date' => '2026-08-20']);
        $this->withTenant($first->agency);
        $range = Ledger::factory()->make(['agency_id' => $first->agency_id, 'employee_id' => $first->employee_id, 'starts' => '2026-08-15', 'ends' => '2026-08-20']);

        $this->assertSame([$first->id, $last->id], $range->workdays()->orderBy('date')->pluck('id')->all());
    }

    public function test_one_workday_can_appear_in_overlapping_ledger_ranges(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);
        $this->withTenant($workday->agency);
        $first = Ledger::factory()->make(['agency_id' => $workday->agency_id, 'employee_id' => $workday->employee_id, 'starts' => '2026-08-01', 'ends' => '2026-08-15']);
        $second = Ledger::factory()->make(['agency_id' => $workday->agency_id, 'employee_id' => $workday->employee_id, 'starts' => '2026-08-15', 'ends' => '2026-08-31']);

        $this->assertSame($workday->id, $first->workdays()->sole()->id);
        $this->assertSame($workday->id, $second->workdays()->sole()->id);
    }

    public function test_a_workday_cannot_be_moved_into_a_locked_range(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-20']);
        Ledger::factory()->create(['agency_id' => $workday->agency_id, 'employee_id' => $workday->employee_id, 'starts' => '2026-08-01', 'ends' => '2026-08-15']);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->where('id', $workday->id)->update(['date' => '2026-08-15']));
    }

    public function test_the_shift_attribute_is_the_snapshot_not_the_related_model(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $this->assertIsArray($workday->shift);
        $this->assertSame('Standard', $workday->shift['shift']['name']);
        $this->assertArrayHasKey('slots', $workday->shift['shift']);
    }

    public function test_the_factory_snapshot_matches_what_the_orchestrator_writes(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);
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

    public function test_a_workday_cannot_be_inserted_on_a_locked_ledger(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $ledger = $this->lockWorkday($workday);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->insert(
            $this->workdayRow($workday)
        ));
    }

    public function test_a_workday_cannot_be_updated_on_a_locked_ledger(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $ledger = $this->lockWorkday($workday);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->where('id', $workday->id)->update([
            'status' => 'absent',
        ]));
    }

    public function test_a_workday_cannot_be_deleted_on_a_locked_ledger(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $ledger = $this->lockWorkday($workday);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->where('id', $workday->id)->delete());
    }

    public function test_a_workday_cannot_be_re_dated_out_of_a_locked_range(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-30']);
        $this->lockWorkday($workday);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->where('id', $workday->id)->update(['date' => '2026-09-01']));
    }

    public function test_workday_writes_succeed_once_the_ledger_is_unlocked(): void
    {
        $workday = Workday::factory()->create(['date' => '2026-08-15']);

        $ledger = $this->lockWorkday($workday);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('workdays')->where('id', $workday->id)->update([
            'status' => 'absent',
        ]));

        DB::table('ledgers')->where('id', $ledger->id)->update(['unlocked_at' => now(), 'unlocked_by' => $ledger->locked_by]);

        DB::table('workdays')->where('id', $workday->id)->update(['status' => 'absent']);
        $this->assertDatabaseHas('workdays', ['id' => $workday->id, 'status' => 'absent']);

        $id = (string) Str::ulid();
        DB::table('workdays')->insert($this->workdayRow($workday, ['id' => $id]));
        $this->assertDatabaseHas('workdays', ['id' => $id]);

        DB::table('workdays')->where('id', $id)->delete();
        $this->assertDatabaseMissing('workdays', ['id' => $id]);
    }

    private function lockWorkday(Workday $workday): Ledger
    {
        return Ledger::factory()->create(['agency_id' => $workday->agency_id, 'employee_id' => $workday->employee_id, 'starts' => '2026-08-01', 'ends' => '2026-08-31']);
    }
}

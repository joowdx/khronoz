<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Turn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Like `shifts` and unlike `teams`, a schedule may belong to the platform
 * agency — that is where the defaults live — so there is no
 * agency_not_platform test to write here.
 */
class ScheduleTest extends TestCase
{
    /** @return array<string, mixed> */
    private function scheduleRow(string $agency, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $agency,
            'name' => 'Cycle '.Str::random(6),
            'length' => 7,
            'fallback_shift_id' => null,
            'origin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_schedule_needs_an_agency(): void
    {
        $this->assertDatabaseRefuses('23502', fn () => DB::table('schedules')->insert(
            $this->scheduleRow(Agency::factory()->create()->id, ['agency_id' => null])
        ));
    }

    public function test_agency_with_schedules_cannot_be_deleted(): void
    {
        $schedule = Schedule::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $schedule->agency_id)->delete());
    }

    public function test_name_is_unique_within_an_agency(): void
    {
        $schedule = Schedule::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('schedules')->insert(
            $this->scheduleRow($schedule->agency_id, ['name' => $schedule->name])
        ));
    }

    /** Ruling P4: the primary key masks any violation of the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'schedules_id_agency_id_unique'"));
    }

    /**
     * Bounded 1 to 366. A length of 1 is legal — every day the same — and the
     * upper bound is a year because beyond that a cycle is really a calendar,
     * which is what holidays and exemptions are for.
     */
    public function test_length_is_bounded_to_a_year(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Schedule::factory()->create(['length' => 0]));
        $this->assertDatabaseRefuses('23514', fn () => Schedule::factory()->create(['length' => 367]));

        foreach ([1, 366] as $edge) {
            $accepted = Schedule::factory()->create(['length' => $edge]);
            $this->assertDatabaseHas('schedules', ['id' => $accepted->id, 'length' => $edge]);
        }
    }

    /** schedules_fallback_shift_id_agency_id_foreign, insert side: a shift of another agency. */
    public function test_fallback_shift_must_share_the_schedules_agency(): void
    {
        $foreign = Shift::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Schedule::factory()->create(['fallback_shift_id' => $foreign->id]));
    }

    /** Same FK, delete side. */
    public function test_shift_used_as_a_fallback_cannot_be_deleted(): void
    {
        $shift = Shift::factory()->create();
        Schedule::factory()->create(['agency_id' => $shift->agency_id, 'fallback_shift_id' => $shift->id]);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('shifts')->where('id', $shift->id)->delete());
    }

    /** origin_is_platform (P0001): a copy's ancestry may only point at a platform-owned row. */
    public function test_an_origin_must_be_a_platform_owned_schedule(): void
    {
        $private = Schedule::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => Schedule::factory()->copiedFrom($private)->create());

        $default = Schedule::factory()->create(['agency_id' => $this->platform()->id]);
        $copy = Schedule::factory()->copiedFrom($default)->create();

        $this->assertDatabaseHas('schedules', ['id' => $copy->id, 'origin_id' => $default->id]);
    }

    /** The trigger stays silent on a nonexistent origin so the FK's own 23503 stays reachable. */
    public function test_an_origin_that_does_not_exist_is_refused_by_the_foreign_key(): void
    {
        $this->assertDatabaseRefuses('23503', fn () => Schedule::factory()->create(['origin_id' => (string) Str::ulid()]));
    }

    /**
     * turns_complete on the schedule side (P0001), and this test is the whole
     * reason the constraint is testable at all.
     *
     * It is the schema's only DEFERRABLE INITIALLY DEFERRED constraint, so it
     * fires at COMMIT — and the suite runs every test inside a transaction it
     * rolls back, so the check would **never run** and this test would pass
     * against a completely absent constraint. `SET CONSTRAINTS ALL IMMEDIATE`
     * inside the closure is what forces it to fire on the statement instead.
     *
     * Two violations: a schedule created with no turns at all (which is why
     * INSERT is on the trigger and not only UPDATE OF length — measured, a
     * turn-less schedule was otherwise accepted and then never re-checked),
     * and a length widened without adding the turn it now needs.
     */
    public function test_a_schedule_must_have_a_complete_set_of_turns(): void
    {
        $this->assertDatabaseRefuses('P0001', function () {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            Schedule::factory()->create(['length' => 7]);
        });

        $this->assertDatabaseRefuses('P0001', function () {
            $schedule = Schedule::factory()->withTurns()->create(['length' => 7]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::table('schedules')->where('id', $schedule->id)->update(['length' => 8]);
        });

        // Widening the length *and* adding the turn, in one transaction: accepted.
        $schedule = Schedule::factory()->withTurns()->create(['length' => 7]);
        $shift = Shift::factory()->create(['agency_id' => $schedule->agency_id]);
        DB::table('schedules')->where('id', $schedule->id)->update(['length' => 8]);
        Turn::factory()->create([
            'agency_id' => $schedule->agency_id,
            'schedule_id' => $schedule->id,
            'shift_id' => $shift->id,
            'position' => 7,
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame(8, DB::table('turns')->where('schedule_id', $schedule->id)->count());
    }

    /**
     * A complete cycle at every length the doc's worked examples use: 1 (every
     * day the same), 3 (the 24/48 guard post), 7 (the standard week), 8 (the
     * 12-hour 2-2-4 rotation) and 21 (the three-team hospital rotation).
     *
     * All five are built first and the constraint forced once at the end, not
     * per iteration, and that ordering is the point: `SET CONSTRAINTS ALL
     * IMMEDIATE` lasts for the whole transaction, so forcing it inside the
     * loop makes every later schedule INSERT fire the check before its own
     * turns exist — which is a fact about the test, not about the schema
     * (MEASURED: length 3 failed that way, having passed at length 1).
     */
    public function test_a_complete_cycle_is_accepted_at_every_documented_length(): void
    {
        $lengths = [1, 3, 7, 8, 21];
        $schedules = [];

        foreach ($lengths as $length) {
            $schedules[$length] = Schedule::factory()->withTurns()->create(['length' => $length]);
        }

        // Fires every pending check at once, so a single statement validates
        // all five cycles.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        foreach ($lengths as $length) {
            $this->assertSame($length, DB::table('turns')->where('schedule_id', $schedules[$length]->id)->count());
        }
    }
}

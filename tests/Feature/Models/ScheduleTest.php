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
    /**
     * @return array<string, mixed>
     */
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

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'schedules_id_agency_id_unique'"));
    }

    public function test_length_is_bounded_to_a_year(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Schedule::factory()->create(['length' => 0]));
        $this->assertDatabaseRefuses('23514', fn () => Schedule::factory()->create(['length' => 367]));

        foreach ([1, 366] as $edge) {
            $accepted = Schedule::factory()->create(['length' => $edge]);
            $this->assertDatabaseHas('schedules', ['id' => $accepted->id, 'length' => $edge]);
        }
    }

    public function test_fallback_shift_must_share_the_schedules_agency(): void
    {
        $foreign = Shift::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Schedule::factory()->create(['fallback_shift_id' => $foreign->id]));
    }

    public function test_shift_used_as_a_fallback_cannot_be_deleted(): void
    {
        $shift = Shift::factory()->create();
        Schedule::factory()->create(['agency_id' => $shift->agency_id, 'fallback_shift_id' => $shift->id]);

        $this->assertDatabaseRefuses('23001', fn () => DB::table('shifts')->where('id', $shift->id)->delete());
    }

    public function test_an_origin_must_be_a_platform_owned_schedule(): void
    {
        $private = Schedule::factory()->create();

        $this->assertDatabaseRefuses('P0001', fn () => Schedule::factory()->copiedFrom($private)->create());

        $default = Schedule::factory()->create(['agency_id' => $this->platform()->id]);
        $copy = Schedule::factory()->copiedFrom($default)->create();

        $this->assertDatabaseHas('schedules', ['id' => $copy->id, 'origin_id' => $default->id]);
    }

    public function test_an_origin_that_does_not_exist_is_refused_by_the_foreign_key(): void
    {
        $this->assertDatabaseRefuses('23503', fn () => Schedule::factory()->create(['origin_id' => (string) Str::ulid()]));
    }

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

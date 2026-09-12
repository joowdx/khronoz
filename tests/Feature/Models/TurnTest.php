<?php

namespace Tests\Feature\Models;

use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Turn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TurnTest extends TestCase
{
    public function test_turn_needs_an_agency(): void
    {
        $schedule = Schedule::factory()->create();
        $shift = Shift::factory()->create(['agency_id' => $schedule->agency_id]);

        $this->assertDatabaseRefuses('23502', fn () => DB::table('turns')->insert([
            'id' => (string) Str::ulid(),
            'agency_id' => null,
            'schedule_id' => $schedule->id,
            'shift_id' => $shift->id,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_a_schedule_cannot_have_two_turns_at_one_position(): void
    {
        $turn = Turn::factory()->create(['position' => 3]);

        $this->assertDatabaseRefuses('23505', fn () => Turn::factory()->create([
            'agency_id' => $turn->agency_id,
            'schedule_id' => $turn->schedule_id,
            'shift_id' => $turn->shift_id,
            'position' => 3,
        ]));

        // The same position in a different schedule: accepted.
        $accepted = Turn::factory()->create([
            'agency_id' => $turn->agency_id,
            'shift_id' => $turn->shift_id,
            'position' => 3,
        ]);
        $this->assertDatabaseHas('turns', ['id' => $accepted->id, 'position' => 3]);
    }

    public function test_position_cannot_be_negative(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Turn::factory()->create(['position' => -1]));
    }

    public function test_schedule_must_share_the_turns_agency(): void
    {
        $foreign = Schedule::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Turn::factory()->create(['schedule_id' => $foreign->id]));
    }

    public function test_schedule_with_turns_cannot_be_deleted(): void
    {
        $turn = Turn::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('schedules')->where('id', $turn->schedule_id)->delete());
    }

    public function test_shift_must_share_the_turns_agency(): void
    {
        $foreign = Shift::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Turn::factory()->create(['shift_id' => $foreign->id]));
    }

    public function test_shift_used_by_a_turn_cannot_be_deleted(): void
    {
        $turn = Turn::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('shifts')->where('id', $turn->shift_id)->delete());
    }

    public function test_a_turn_cannot_be_removed_from_a_complete_cycle(): void
    {
        $schedule = Schedule::factory()->withTurns()->create(['length' => 7]);

        $this->assertDatabaseRefuses('P0001', function () use ($schedule) {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::table('turns')->where('schedule_id', $schedule->id)->where('position', 3)->delete();
        });
    }

    public function test_a_schedule_and_its_turns_can_be_dropped_together(): void
    {
        $schedule = Schedule::factory()->withTurns()->create(['length' => 7]);

        DB::table('turns')->where('schedule_id', $schedule->id)->delete();
        DB::table('schedules')->where('id', $schedule->id)->delete();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
        $this->assertSame(0, DB::table('turns')->where('schedule_id', $schedule->id)->count());
    }
}

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
 * turns_agency_id_foreign is untested on the insert side for the reason
 * Ruling P5 gives on deployments: both paired FKs require their parents to
 * carry a valid agency_id, so no row exists where this FK alone fails, and a
 * 23503 caught here could come from either pair. Its delete side is covered
 * transitively — a turn can only exist under an agency that still has the
 * schedule and shift it names.
 */
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

    /**
     * UNIQUE (schedule_id, position): one shift per day of the cycle. This is
     * the constraint that lets turns_complete get away with checking only the
     * count and the maximum — with no duplicates and no negatives, those two
     * facts force exactly the set 0 to length - 1.
     */
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

    /** turns_schedule_id_agency_id_foreign, insert side: a schedule of another agency. */
    public function test_schedule_must_share_the_turns_agency(): void
    {
        $foreign = Schedule::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Turn::factory()->create(['schedule_id' => $foreign->id]));
    }

    /**
     * Same FK, delete side. Deleting the turns first is what makes dropping a
     * schedule possible at all — and turns_complete tolerates that, since it
     * returns early once the schedule is gone.
     */
    public function test_schedule_with_turns_cannot_be_deleted(): void
    {
        $turn = Turn::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('schedules')->where('id', $turn->schedule_id)->delete());
    }

    /** turns_shift_id_agency_id_foreign, insert side: a shift of another agency. */
    public function test_shift_must_share_the_turns_agency(): void
    {
        $foreign = Shift::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Turn::factory()->create(['shift_id' => $foreign->id]));
    }

    /** Same FK, delete side: a shift still used by a cycle cannot be removed. */
    public function test_shift_used_by_a_turn_cannot_be_deleted(): void
    {
        $turn = Turn::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('shifts')->where('id', $turn->shift_id)->delete());
    }

    /**
     * turns_complete on the turns side (P0001), and the reason DELETE is on
     * the trigger: removing a turn from a complete cycle would otherwise
     * leave a gap the resolver reads as a missing position, silently, since
     * `(D - anchor) mod length` would land on a position with no row.
     *
     * `SET CONSTRAINTS ALL IMMEDIATE` inside the closure for the reason
     * ScheduleTest explains: the constraint is DEFERRED, the suite never
     * commits, so without this the assertion passes against no constraint
     * at all.
     */
    public function test_a_turn_cannot_be_removed_from_a_complete_cycle(): void
    {
        $schedule = Schedule::factory()->withTurns()->create(['length' => 7]);

        $this->assertDatabaseRefuses('P0001', function () use ($schedule) {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::table('turns')->where('schedule_id', $schedule->id)->where('position', 3)->delete();
        });
    }

    /**
     * Dropping a whole cycle is legitimate and must not be refused: the
     * function returns early when the schedule itself is gone, which is what
     * lets the turns and their schedule be deleted in one transaction.
     */
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

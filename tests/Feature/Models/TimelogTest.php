<?php

namespace Tests\Feature\Models;

use App\Models\Enrollment;
use App\Models\Sync;
use App\Models\Terminal;
use App\Models\Timelog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One test per constraint on `timelogs` (docs/design/07-constraints.md).
 * Resolution behaviour — the two triggers — is TimelogResolutionTest.
 *
 * No `agency_not_platform` test and no such trigger: a timelog needs a
 * terminal, and terminals refuse the platform row already.
 */
class TimelogTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function timelogRow(Timelog $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'terminal_id' => $like->terminal_id,
            'sync_id' => $like->sync_id,
            'employee_id' => null,
            'enrollment_id' => null,
            'uid' => (string) fake()->unique()->numberBetween(10000, 99999),
            'time' => '2026-09-02 08:01:23',
            'state' => 0,
            'mode' => 1,
            'source' => 'device',
            'user_id' => null,
            'voided_at' => null,
            'reason' => null,
            'created_at' => now(),
            ...$overrides,
        ];
    }

    public function test_timelog_needs_an_agency(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['agency_id' => null])
        ));
    }

    public function test_terminal_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['terminal_id' => null])
        ));
    }

    public function test_uid_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['uid' => null])
        ));
    }

    public function test_time_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['time' => null])
        ));
    }

    public function test_state_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['state' => null])
        ));
    }

    public function test_mode_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['mode' => null])
        ));
    }

    public function test_source_is_required(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['source' => null])
        ));
    }

    public function test_source_must_be_a_known_value(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['source' => 'imported'])
        ));
    }

    public function test_state_must_fit_in_a_byte(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['state' => 256])
        ));
    }

    public function test_mode_must_fit_in_a_byte(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['mode' => 256])
        ));
    }

    public function test_an_unknown_but_in_range_state_is_kept(): void
    {
        $timelog = Timelog::factory()->create(['state' => 9]);

        $this->assertSame(9, $timelog->fresh()->state);
    }

    public function test_a_device_timelog_must_name_its_sync(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['source' => 'device', 'sync_id' => null])
        ));
    }

    public function test_a_manual_timelog_cannot_name_a_sync(): void
    {
        $timelog = Timelog::factory()->create();
        $user = User::factory()->create(['agency_id' => $timelog->agency_id]);

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['source' => 'manual', 'user_id' => $user->id])
        ));
    }

    public function test_a_manual_timelog_must_name_the_user_who_entered_it(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['source' => 'manual', 'sync_id' => null, 'user_id' => null])
        ));
    }

    public function test_a_device_timelog_cannot_name_a_recording_user(): void
    {
        $timelog = Timelog::factory()->create();
        $user = User::factory()->create(['agency_id' => $timelog->agency_id]);

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['user_id' => $user->id])
        ));
    }

    public function test_a_void_must_carry_a_reason(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['voided_at' => now(), 'reason' => null])
        ));
    }

    public function test_the_same_punch_cannot_be_recorded_twice(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, [
                'uid' => $timelog->uid,
                'time' => $timelog->time->toDateTimeString(),
                'state' => $timelog->state,
                'mode' => $timelog->mode,
            ])
        ));
    }

    public function test_two_punches_differing_only_by_state_are_both_kept(): void
    {
        $timelog = Timelog::factory()->create(['state' => 0]);

        DB::table('timelogs')->insert($this->timelogRow($timelog, [
            'uid' => $timelog->uid,
            'time' => $timelog->time->toDateTimeString(),
            'state' => 1,
            'mode' => $timelog->mode,
        ]));

        $this->assertSame(
            [0, 1],
            DB::table('timelogs')->where('uid', $timelog->uid)->orderBy('state')->pluck('state')->all(),
        );
    }

    public function test_terminal_must_share_the_timelogs_agency(): void
    {
        $terminal = Terminal::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Timelog::factory()->create(['terminal_id' => $terminal->id]));
    }

    public function test_terminal_with_a_timelog_cannot_be_deleted(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('terminals')->where('id', $timelog->terminal_id)->delete());
    }

    public function test_sync_must_share_the_timelogs_terminal(): void
    {
        $timelog = Timelog::factory()->create();
        $other = Sync::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => DB::table('timelogs')->insert(
            $this->timelogRow($timelog, ['sync_id' => $other->id])
        ));
    }

    public function test_the_sync_foreign_key_restricts_deletion(): void
    {
        $this->assertSame(
            'FOREIGN KEY (sync_id, terminal_id) REFERENCES syncs(id, terminal_id) ON UPDATE RESTRICT ON DELETE RESTRICT',
            DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'timelogs_sync_id_terminal_id_foreign'")->def,
        );
    }

    public function test_a_manual_timelog_with_no_sync_is_accepted(): void
    {
        $timelog = Timelog::factory()->create();
        $user = User::factory()->create(['agency_id' => $timelog->agency_id]);
        $id = (string) Str::ulid();

        DB::table('timelogs')->insert($this->timelogRow($timelog, [
            'id' => $id,
            'source' => 'manual',
            'sync_id' => null,
            'user_id' => $user->id,
        ]));

        $this->assertDatabaseHas('timelogs', [
            'id' => $id,
            'source' => 'manual',
            'sync_id' => null,
        ]);
    }

    public function test_user_who_entered_a_timelog_cannot_be_deleted(): void
    {
        $timelog = Timelog::factory()->manual()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('users')->where('id', $timelog->user_id)->delete());
    }

    public function test_enrollment_with_a_resolved_timelog_cannot_be_deleted(): void
    {
        $enrollment = Enrollment::factory()->create();
        $timelog = Timelog::factory()->resolving($enrollment)->create();

        $this->assertNotNull($timelog->fresh()->enrollment_id);
        $this->assertDatabaseRefuses('23001', fn () => DB::table('enrollments')->where('id', $enrollment->id)->delete());
    }

    public function test_id_and_employee_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'timelogs_id_employee_id_unique'"));
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'timelogs_id_agency_id_unique'"));
    }

    public function test_the_resolved_pair_check_is_declared(): void
    {
        $this->assertSame(
            'CHECK (((enrollment_id IS NULL) = (employee_id IS NULL)))',
            DB::selectOne("select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'timelogs_resolved_pair'")->def,
        );
    }

    public function test_the_table_has_no_updated_at_column(): void
    {
        $this->assertNull(DB::selectOne(
            "select 1 as present from information_schema.columns where table_name = 'timelogs' and column_name = 'updated_at'"
        ));

        $timelog = Timelog::factory()->create();

        $this->assertTrue($timelog->void('Duplicate scan', User::factory()->create(['agency_id' => $timelog->agency_id])));
        $this->assertNotNull($timelog->fresh()->voided_at);
        $this->assertSame('Duplicate scan', $timelog->fresh()->reason);
    }
}

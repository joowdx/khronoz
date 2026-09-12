<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Enrollment;
use App\Models\Sync;
use App\Models\Timelog;
use App\Models\User;
use App\Support\AppRoleGrants;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimelogImmutabilityTest extends TestCase
{
    public function test_the_app_role_cannot_delete_a_timelog(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->delete());
    }

    public function test_the_app_role_cannot_truncate_timelogs(): void
    {
        Timelog::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::statement('TRUNCATE timelogs'));
    }

    public function test_the_app_role_cannot_change_what_the_device_recorded(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['time' => '2026-01-01 00:00:00']));
        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['state' => 5]));
        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['uid' => '9999']));
    }

    public function test_the_app_role_cannot_say_who_punched(): void
    {
        $enrollment = Enrollment::factory()->create();
        $timelog = Timelog::factory()->resolving($enrollment)->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['employee_id' => null]));
        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['enrollment_id' => null]));
    }

    public function test_the_app_role_may_void_a_timelog_through_the_model(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertTrue($timelog->void('Duplicate scan', $this->clerk()));

        $voided = $timelog->fresh();

        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Duplicate scan', $voided->reason);
    }

    public function test_the_app_role_may_attribute_a_void_but_not_a_punch(): void
    {
        $timelog = Timelog::factory()->create();
        $clerk = $this->clerk();

        $timelog->void('Duplicate scan', $clerk);

        $this->assertSame($clerk->id, $timelog->fresh()->voided_by);
        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['user_id' => $clerk->id]));
    }

    public function test_a_void_must_name_an_actor_and_an_actor_implies_a_void(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->where('id', $timelog->id)
            ->update(['voided_at' => now(), 'reason' => 'Duplicate scan']));

        $this->assertDatabaseRefuses('23514', fn () => DB::table('timelogs')->where('id', $timelog->id)
            ->update(['voided_by' => $this->clerk()->id]));
    }

    public function test_a_voided_timelog_cannot_be_changed_again(): void
    {
        $timelog = Timelog::factory()->voided('Duplicate scan')->create();

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('timelogs')->where('id', $timelog->id)
            ->update(['voided_at' => now(), 'reason' => 'oops', 'voided_by' => $this->clerk()->id]));

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('timelogs')->where('id', $timelog->id)
            ->update(['reason' => 'a better reason']));

        $this->assertSame('Duplicate scan', $timelog->fresh()->reason);
    }

    public function test_a_voided_timelog_remains_readable(): void
    {
        $timelog = Timelog::factory()->create();
        $timelog->void('Duplicate scan', $this->clerk());

        $this->withTenant(Agency::findOrFail($timelog->agency_id));

        $this->assertNotNull(Timelog::find($timelog->id));
        $this->assertSame(0, Timelog::standing()->where('id', $timelog->id)->count());
        $this->assertSame(1, Timelog::where('id', $timelog->id)->count());
    }

    public function test_the_app_role_cannot_delete_a_sync(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('syncs')->where('id', $sync->id)->delete());
    }

    public function test_the_app_role_may_close_a_sync(): void
    {
        $sync = Sync::factory()->create();

        $sync->update([
            'status' => 'completed',
            'finished_at' => now(),
            'received' => 3,
            'accepted' => 3,
            'duplicates' => 0,
            'rejected' => 0,
        ]);

        $this->assertSame(3, $sync->fresh()->accepted);
    }

    public function test_re_running_the_deploy_grants_does_not_restore_write_access(): void
    {
        $timelog = Timelog::factory()->create();

        AppRoleGrants::apply();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->delete());
        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['time' => '2026-01-01 00:00:00']));
        $this->assertDatabaseRefuses('42501', fn () => DB::table('syncs')->where('id', $timelog->sync_id)->delete());

        // And the narrow grant it must not have widened.
        $this->assertTrue($timelog->fresh()->void('Duplicate scan', $this->clerk()));
    }

    public function test_the_granted_privileges_are_exactly_insert_select_and_a_three_column_update(): void
    {
        $table = DB::table('information_schema.table_privileges')
            ->where('grantee', 'chronoz')->where('table_name', 'timelogs')
            ->orderBy('privilege_type')->pluck('privilege_type')->all();

        $columns = DB::table('information_schema.column_privileges')
            ->where('grantee', 'chronoz')->where('table_name', 'timelogs')->where('privilege_type', 'UPDATE')
            ->orderBy('column_name')->pluck('column_name')->all();

        $this->assertSame(['INSERT', 'SELECT'], $table);
        $this->assertSame(['reason', 'voided_at', 'voided_by'], $columns);
    }

    private function clerk(): User
    {
        return User::factory()->create();
    }
}
/** @return void */

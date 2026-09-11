<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Enrollment;
use App\Models\Sync;
use App\Models\Timelog;
use App\Support\AppRoleGrants;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `03-terminals.md` rule 1: a timelog is immutable, nothing is ever pruned,
 * "enforced by privilege". This is that enforcement, asserted rather than
 * asserted-about.
 *
 * These tests are only meaningful because of a property of the test
 * environment worth stating: the default connection runs as `chronoz`, which
 * is `rolsuper = false`, a member of no role, and owns none of the tables —
 * `khronoz` owns them all. A superuser ignores GRANT and REVOKE entirely, so
 * the guarantee holds only while the application connects as the app role.
 * That is why `DB_OWNER_*` must stay unset wherever Octane or Horizon runs.
 *
 * The predecessor had four independent ways to destroy one of these rows: a
 * flush verb that hard-deleted, a `Prunable` scheduled every minute, and
 * cascading deletes from both the scanner and its own self-FK. None of them
 * could be built against this.
 */
class TimelogImmutabilityTest extends TestCase
{
    public function test_the_app_role_cannot_delete_a_timelog(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->delete());
    }

    /** Not even all of them at once — there is no privileged bulk path. */
    public function test_the_app_role_cannot_truncate_timelogs(): void
    {
        Timelog::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::statement('TRUNCATE timelogs'));
    }

    /** What the device said is not editable. */
    public function test_the_app_role_cannot_change_what_the_device_recorded(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['time' => '2026-01-01 00:00:00']));
        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['state' => 5]));
        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['uid' => '9999']));
    }

    /**
     * And it cannot say who punched. Resolution is `timelogs_resolve`'s job
     * and nothing else's — this privilege is what makes that statement true
     * rather than merely intended.
     */
    public function test_the_app_role_cannot_say_who_punched(): void
    {
        $enrollment = Enrollment::factory()->create();
        $timelog = Timelog::factory()->resolving($enrollment)->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['employee_id' => null]));
        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['enrollment_id' => null]));
    }

    /**
     * The two columns it *can* write, exercised **through the Eloquent model**
     * rather than the query builder — which is the point of the test.
     *
     * A model write would also touch `updated_at` if the column existed, and
     * that single extra column would fail 42501 and take voiding with it. The
     * column's absence and this privilege are one design, and only a model
     * call proves they fit together.
     */
    public function test_the_app_role_may_void_a_timelog_through_the_model(): void
    {
        $timelog = Timelog::factory()->create();

        $this->assertTrue($timelog->void('Duplicate scan'));

        $voided = $timelog->fresh();

        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Duplicate scan', $voided->reason);
    }

    /** A voided row stays visible. Nothing is ever pruned, and nothing is hidden by scope. */
    public function test_a_voided_timelog_remains_readable(): void
    {
        $timelog = Timelog::factory()->create();
        $timelog->void('Duplicate scan');

        $this->withTenant(Agency::findOrFail($timelog->agency_id));

        $this->assertNotNull(Timelog::find($timelog->id));
        $this->assertSame(0, Timelog::standing()->where('id', $timelog->id)->count());
        $this->assertSame(1, Timelog::where('id', $timelog->id)->count());
    }

    /** A run record that can be deleted is a run that can be denied. */
    public function test_the_app_role_cannot_delete_a_sync(): void
    {
        $sync = Sync::factory()->create();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('syncs')->where('id', $sync->id)->delete());
    }

    /** But it may close one — the UPDATE that writes the final counters. */
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

    /**
     * **The test this commit exists for.**
     *
     * `AppRoleGrants::apply()` runs `GRANT SELECT, INSERT, UPDATE, DELETE ON
     * ALL TABLES IN SCHEMA public`, and `php artisan db:grant` re-runs it as a
     * post-deploy step after an owner-role rotation. A REVOKE written only
     * into the timelogs migration would therefore be silently undone by the
     * next deploy — no error, no failing test, and immutability would simply
     * stop existing.
     *
     * So `restrict()` is called from inside `apply()` as well as from the
     * migrations, and this asserts the loop closes. Without that call this
     * test fails, which is the entire reason to write it.
     *
     * Safe to run mid-suite despite touching the owner connection, which the
     * test transaction does not wrap: apply() grants everything and then
     * narrows it straight back, so the net effect on a correct build is
     * nothing. On an incorrect one it fails loudly, which is what is wanted.
     */
    public function test_re_running_the_deploy_grants_does_not_restore_write_access(): void
    {
        $timelog = Timelog::factory()->create();

        AppRoleGrants::apply();

        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->delete());
        $this->assertDatabaseRefuses('42501', fn () => DB::table('timelogs')->where('id', $timelog->id)->update(['time' => '2026-01-01 00:00:00']));
        $this->assertDatabaseRefuses('42501', fn () => DB::table('syncs')->where('id', $timelog->sync_id)->delete());

        // And the narrow grant it must not have widened.
        $this->assertTrue($timelog->fresh()->void('Duplicate scan'));
    }

    /**
     * The privileges are invisible to every constraint catalog, so they get a
     * read-back of their own: table-level INSERT and SELECT, no DELETE, no
     * table-wide UPDATE, and UPDATE on exactly two columns.
     */
    public function test_the_granted_privileges_are_exactly_insert_select_and_a_two_column_update(): void
    {
        $table = DB::table('information_schema.table_privileges')
            ->where('grantee', 'chronoz')->where('table_name', 'timelogs')
            ->orderBy('privilege_type')->pluck('privilege_type')->all();

        $columns = DB::table('information_schema.column_privileges')
            ->where('grantee', 'chronoz')->where('table_name', 'timelogs')->where('privilege_type', 'UPDATE')
            ->orderBy('column_name')->pluck('column_name')->all();

        $this->assertSame(['INSERT', 'SELECT'], $table);
        $this->assertSame(['reason', 'voided_at'], $columns);
    }
}

<?php

namespace Tests\Feature\Database;

use App\Listeners\EnsureMigrationsRunAsOwner;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class AppRoleTest extends TestCase
{
    public function test_app_connection_uses_the_app_role(): void
    {
        $this->assertSame('khronoz_app', DB::selectOne('select current_user as name')->name);
    }

    public function test_app_role_cannot_create_tables(): void
    {
        $this->assertDatabaseRefuses('42501', fn () => DB::statement('create table smuggled (id int)'));
    }

    /**
     * khronoz_app never runs a migration, so it keeps SELECT on `migrations`
     * (test_app_role_can_read_and_write_migrated_tables's sibling never
     * exercises this table) but not the write privileges the blanket grant
     * in AppRoleGrants gives it everywhere else — see the REVOKE at the end
     * of AppRoleGrants::apply(). This proves the refusal on a fresh
     * migrate:fresh, which every test in this suite runs against; it does
     * not by itself prove the REVOKE reaches a database that already ran
     * 0000_00_00_000001_prepare_database; that is what running `db:grant`
     * against the dev database by hand verifies.
     */
    public function test_app_role_cannot_write_to_migrations(): void
    {
        $this->assertDatabaseRefuses('42501', fn () => DB::table('migrations')->insert([
            'migration' => 'smuggled_migration',
            'batch' => 1,
        ]));
    }

    public function test_app_role_can_read_and_write_migrated_tables(): void
    {
        DB::table('sessions')->insert(['id' => 'probe', 'payload' => '', 'last_activity' => 0]);

        $this->assertSame(1, DB::table('sessions')->where('id', 'probe')->count());
    }

    public function test_btree_gist_is_installed(): void
    {
        $this->assertSame(1, DB::table('pg_extension')->where('extname', 'btree_gist')->count());
    }

    public function test_migrations_refuse_any_connection_but_owner(): void
    {
        $this->assertSame('pgsql', DB::getDefaultConnection());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer migrate');

        (new EnsureMigrationsRunAsOwner)->handle(new MigrationsStarted('up'));
    }

    public function test_migrations_guard_strips_connection_suffix_before_comparing(): void
    {
        DB::setDefaultConnection('owner::direct');

        (new EnsureMigrationsRunAsOwner)->handle(new MigrationsStarted('up'));

        DB::setDefaultConnection('pgsql::direct');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer migrate');

        (new EnsureMigrationsRunAsOwner)->handle(new MigrationsStarted('up'));
    }

    /**
     * Unset DB_OWNER_* only documents the privilege boundary (config/database.php
     * has no fallback credentials any more); AppServiceProvider::guardOwnerConnection()
     * is what actually enforces it. PHPUnit itself runs as a CLI process, so
     * runningInConsole() is genuinely true for every other test in this class —
     * flipping the Application's own memoized flag is the only way to exercise
     * the branch that refuses a non-CLI request without a second process. That
     * branch is unreachable from Octane's or Horizon's workers even in a real
     * deploy, not just in this test file: both run as long-lived CLI processes
     * like PHPUnit itself, so runningInConsole() is true there too and the
     * guard cannot single them out — see guardOwnerConnection()'s docblock.
     * DB::purge('owner') before and after: LazilyRefreshDatabase's
     * one-time migrate:fresh --database=owner already resolved and cached this
     * connection earlier in the run, and the cached instance must not survive
     * into later tests (including AgencyScopeTest's own DB::connection('owner')
     * call) that expect a normal, working owner connection.
     */
    public function test_owner_connection_refuses_resolution_outside_a_console_run(): void
    {
        DB::purge('owner');

        $flag = new ReflectionProperty($this->app, 'isRunningInConsole');
        $flag->setAccessible(true);
        $original = $flag->getValue($this->app);
        $flag->setValue($this->app, false);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('composer migrate');

            DB::connection('owner');
        } finally {
            $flag->setValue($this->app, $original);
            DB::purge('owner');
        }
    }

    /**
     * The grants a fresh install gets from 0000_00_00_000001_prepare_database
     * must also work re-issued by hand (php artisan db:grant) after an owner
     * role rotation — see App\Support\AppRoleGrants. Runs against the same
     * already-migrated testing database, so this proves the statements are
     * genuinely idempotent, not just correct on an empty schema.
     */
    public function test_db_grant_command_reapplies_privileges_idempotently(): void
    {
        $this->artisan('db:grant')->assertExitCode(0);

        DB::table('sessions')->insert(['id' => 'probe-grant', 'payload' => '', 'last_activity' => 0]);

        $this->assertSame(1, DB::table('sessions')->where('id', 'probe-grant')->count());
    }
}

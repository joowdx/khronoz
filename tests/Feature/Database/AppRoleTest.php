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
        $this->assertSame('chronoz', DB::selectOne('select current_user as name')->name);
    }

    public function test_app_role_cannot_create_tables(): void
    {
        $this->assertDatabaseRefuses('42501', fn () => DB::statement('create table smuggled (id int)'));
    }

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

    public function test_db_grant_command_reapplies_privileges_idempotently(): void
    {
        $this->artisan('db:grant')->assertExitCode(0);

        DB::table('sessions')->insert(['id' => 'probe-grant', 'payload' => '', 'last_activity' => 0]);

        $this->assertSame(1, DB::table('sessions')->where('id', 'probe-grant')->count());
    }
}

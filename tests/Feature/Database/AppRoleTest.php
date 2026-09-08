<?php

namespace Tests\Feature\Database;

use App\Listeners\EnsureMigrationsRunAsOwner;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
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
}

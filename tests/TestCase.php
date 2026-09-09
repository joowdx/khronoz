<?php

namespace Tests;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\User;
use Closure;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // migrateFreshUsing() and migrateDatabases() live on the CanConfigureMigrationCommands
    // / RefreshDatabase traits (mixed in through LazilyRefreshDatabase), not on the framework
    // TestCase, so `parent::` cannot reach either once this class declares its own override.
    // Alias the trait's versions instead, the same way LazilyRefreshDatabase itself aliases
    // refreshDatabase().
    use LazilyRefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
        migrateDatabases as baseMigrateDatabases;
    }

    /** Migrations run as the owner; tests then query as the app role. */
    protected function migrateFreshUsing(): array
    {
        return [...$this->baseMigrateFreshUsing(), '--database' => 'owner'];
    }

    /**
     * RefreshDatabaseState::$migrated guards this to once per process, before
     * the first test's transaction begins. Seeding here — rather than --seed
     * or #[Seed], which run inside migrate:fresh as the owner — seeds on the
     * app connection, so the platform row exists exactly as it would in
     * production before any test touches it.
     */
    protected function migrateDatabases(): void
    {
        $this->baseMigrateDatabases();

        $this->artisan('db:seed', ['--class' => PlatformSeeder::class, '--no-interaction' => true]);
    }

    /** The platform row, seeded once for the whole run; see PlatformSeeder. */
    protected function platform(): Agency
    {
        return Agency::platform();
    }

    /**
     * Assert Postgres refused the statement with the given SQLSTATE.
     *
     * Runs $statement inside its own transaction so a second call in the same
     * test can make its own assertion: once one statement errors, Postgres
     * marks the whole surrounding transaction aborted (25P02) and refuses every
     * later command until a rollback, and DB::transaction() nested inside the
     * per-test transaction already open here compiles to a SAVEPOINT, so its
     * automatic rollback on the caught exception undoes only $statement.
     *
     * Takes no $attempts argument and must not gain one: retrying $statement
     * would re-run a statement this method expects to fail, not recover from
     * a transient error.
     */
    protected function assertDatabaseRefuses(string $sqlstate, Closure $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->getCode(), "expected SQLSTATE {$sqlstate}, got {$e->getCode()}: {$e->getMessage()}");

            return;
        }

        $this->fail("expected the database to refuse with SQLSTATE {$sqlstate}");
    }

    /**
     * Log in as a fresh superuser of the platform agency (docs/design/02-
     * access.md rule 3). When $enter is given, marks that agency as the one
     * this platform user has "entered" for the request; SetTenant (Task 6)
     * reads that from session.
     */
    protected function actingAsPlatform(?Agency $enter = null): User
    {
        $user = User::factory()->platform()->create();

        $this->actingAs($user);

        if ($enter) {
            // 'agency' is the session key SetTenant (Task 6) reads to find the
            // agency a platform user has "entered".
            $this->withSession(['agency' => $enter->id]);
        }

        return $user;
    }

    /** Log in as a fresh staff user of $agency holding exactly $permissions. */
    protected function actingAsAgency(Agency $agency, Permission ...$permissions): User
    {
        $user = User::factory()->forAgency($agency)->permissions(...$permissions)->create();

        $this->actingAs($user);

        return $user;
    }
}

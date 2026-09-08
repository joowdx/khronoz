<?php

namespace Tests;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // migrateFreshUsing() lives on the CanConfigureMigrationCommands trait
    // (mixed in through RefreshDatabase), not on the framework TestCase, so
    // `parent::migrateFreshUsing()` cannot reach it once this class declares
    // its own override. Alias the trait's version instead, the same way
    // LazilyRefreshDatabase itself aliases refreshDatabase().
    use LazilyRefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
    }

    /** Migrations run as the owner; tests then query as the app role. */
    protected function migrateFreshUsing(): array
    {
        return [...$this->baseMigrateFreshUsing(), '--database' => 'owner'];
    }

    /** Assert Postgres refused the statement with the given SQLSTATE. */
    protected function assertDatabaseRefuses(string $sqlstate, Closure $statement): void
    {
        try {
            $statement();
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->getCode(), "expected SQLSTATE {$sqlstate}, got {$e->getCode()}: {$e->getMessage()}");

            return;
        }

        $this->fail("expected the database to refuse with SQLSTATE {$sqlstate}");
    }
}

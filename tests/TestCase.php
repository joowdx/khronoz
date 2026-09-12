<?php

namespace Tests;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\User;
use App\Tenancy\Tenant;
use Closure;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // Alias trait methods because parent cannot reach trait overrides.
    use LazilyRefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
        migrateDatabases as baseMigrateDatabases;
    }

    protected function migrateFreshUsing(): array
    {
        return [...$this->baseMigrateFreshUsing(), '--database' => 'owner'];
    }

    protected function migrateDatabases(): void
    {
        $this->baseMigrateDatabases();

        $this->artisan('db:seed', ['--class' => PlatformSeeder::class, '--no-interaction' => true]);
    }

    protected function platform(): Agency
    {
        return Agency::platform();
    }

    protected function assertDatabaseRefuses(string $sqlstate, Closure $statement, ?string $mentioning = null): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->getCode(), "expected SQLSTATE {$sqlstate}, got {$e->getCode()}: {$e->getMessage()}");

            if ($mentioning !== null) {
                $this->assertStringContainsString(
                    $mentioning,
                    $e->getMessage(),
                    "expected the refusal to mention {$mentioning}: {$e->getMessage()}",
                );
            }

            return;
        }

        $this->fail("expected the database to refuse with SQLSTATE {$sqlstate}");
    }

    protected function actingAsPlatform(?Agency $enter = null): User
    {
        $user = User::factory()->acceptedLegal()->platform()->create();

        $this->actingAs($user);

        if ($enter) {
            // SetTenant reads the entered agency from this session key.
            $this->withSession(['agency' => $enter->id]);
        }

        return $user;
    }

    protected function actingAsAgency(Agency $agency, Permission ...$permissions): User
    {
        $user = User::factory()->acceptedLegal()->forAgency($agency)->permissions(...$permissions)->create();

        $this->actingAs($user);

        return $user;
    }

    protected function withTenant(Agency $agency): static
    {
        app(Tenant::class)->set($agency);

        return $this;
    }
}

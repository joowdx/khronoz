<?php

namespace Tests\Feature\Support;

use App\Enums\Preset;
use App\Models\User;
use App\Support\Dashboard;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_horizon_gate_admits_platform_users_only(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertTrue(Dashboard::allows(User::factory()->platform()->create()));
        $this->assertFalse(Dashboard::allows(User::factory()->preset(Preset::Admin)->create()));
        $this->assertFalse(Dashboard::allows(null));
    }

    /**
     * The local branch is an independent OR arm: proven separately from the
     * platform-only branch above so either half can regress on its own.
     */
    public function test_the_local_environment_admits_everyone(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertTrue(Dashboard::allows(null));
        $this->assertTrue(Dashboard::allows(User::factory()->preset(Preset::Viewer)->create()));
    }
}

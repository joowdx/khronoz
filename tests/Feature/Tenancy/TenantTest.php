<?php

namespace Tests\Feature\Tenancy;

use App\Models\Agency;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

class TenantTest extends TestCase
{
    public function test_set_and_forget(): void
    {
        $agency = Agency::factory()->create();
        $tenant = app(Tenant::class);

        $this->assertFalse($tenant->check());
        $tenant->set($agency);
        $this->assertTrue($tenant->check());
        $this->assertSame($agency->id, $tenant->id());
        $tenant->forget();
        $this->assertNull(Context::getHidden('agency'));
        $this->assertFalse($tenant->check());
    }

    public function test_tenant_restores_from_context_for_jobs(): void
    {
        $agency = Agency::factory()->create();
        app(Tenant::class)->set($agency);
        $this->assertSame($agency->id, Context::getHidden('agency'));

        $this->app->forgetScopedInstances();
        $this->assertSame($agency->id, app(Tenant::class)->id());
    }

    public function test_platform_id_is_the_platform_row(): void
    {
        $this->assertSame(Agency::platform()->id, app(Tenant::class)->platformId());
    }
}

<?php

namespace Tests\Feature\Tenancy;

use App\Models\Agency;
use App\Models\Holiday;
use App\Models\Scopes\AgencyOrPlatformScope;
use App\Tenancy\TenantNotResolved;
use Tests\TestCase;

class AgencyOrPlatformScopeTest extends TestCase
{
    /** @return array{0: Agency, 1: Agency} */
    private function calendar(): array
    {
        [$mine, $theirs] = Agency::factory()->count(2)->create();

        Holiday::factory()->national()->create(['name' => 'Bonifacio Day']);
        Holiday::factory()->create(['agency_id' => $mine->id, 'name' => 'Kadayawan Festival']);
        Holiday::factory()->create(['agency_id' => $theirs->id, 'name' => 'Sinulog']);

        return [$mine, $theirs];
    }

    public function test_reads_include_the_platform_agencys_rows(): void
    {
        [$mine] = $this->calendar();
        $this->withTenant($mine);

        $this->assertSame(
            ['Bonifacio Day', 'Kadayawan Festival'],
            Holiday::orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_reads_exclude_another_agencys_rows(): void
    {
        [, $theirs] = $this->calendar();
        $this->withTenant($theirs);

        $this->assertSame(
            ['Bonifacio Day', 'Sinulog'],
            Holiday::orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_the_platform_tenant_sees_only_the_national_calendar(): void
    {
        $this->calendar();
        $this->withTenant($this->platform());

        $this->assertSame(['Bonifacio Day'], Holiday::orderBy('name')->pluck('name')->all());
    }

    public function test_reading_without_a_tenant_throws(): void
    {
        $this->expectException(TenantNotResolved::class);

        Holiday::count();
    }

    public function test_unscoped_reads_need_an_explicit_escape_hatch(): void
    {
        $this->calendar();

        $this->assertSame(3, Holiday::withoutGlobalScope(AgencyOrPlatformScope::class)->count());
    }
}
/** @return void */

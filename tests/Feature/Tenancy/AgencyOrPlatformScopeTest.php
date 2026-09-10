<?php

namespace Tests\Feature\Tenancy;

use App\Models\Agency;
use App\Models\Holiday;
use App\Models\Scopes\AgencyOrPlatformScope;
use App\Tenancy\TenantNotResolved;
use Tests\TestCase;

/**
 * `agency_id IN (own, platform)` — the only scope in the schema that reads two
 * agencies, and `holidays` is its only user (07-constraints.md: "Scoping is
 * `agency_id IN (own, platform)` for holidays and `agency_id = own` for
 * everything else").
 *
 * Tested through the real model rather than a throwaway `Probe` as
 * AgencyScopeTest uses, because half of what is under test is the wiring —
 * that `Holiday` overrides `BelongsToAgency::agencyScope()` and therefore does
 * **not** carry the ordinary AgencyScope as well. A probe would prove the
 * scope class works while leaving that unasserted.
 */
class AgencyOrPlatformScopeTest extends TestCase
{
    /**
     * All three fixtures are built before withTenant(), and they have to be:
     * BelongsToAgency refuses an explicit agency_id that disagrees with a set
     * tenant (TenantMismatch), so a national holiday cannot be created once
     * an ordinary agency is the tenant.
     *
     * @return array{0: Agency, 1: Agency}
     */
    private function calendar(): array
    {
        [$mine, $theirs] = Agency::factory()->count(2)->create();

        Holiday::factory()->national()->create(['name' => 'Bonifacio Day']);
        Holiday::factory()->create(['agency_id' => $mine->id, 'name' => 'Kadayawan Festival']);
        Holiday::factory()->create(['agency_id' => $theirs->id, 'name' => 'Sinulog']);

        return [$mine, $theirs];
    }

    /** The whole point: a national holiday applies to every tenant. */
    public function test_reads_include_the_platform_agencys_rows(): void
    {
        [$mine] = $this->calendar();
        $this->withTenant($mine);

        $this->assertSame(
            ['Bonifacio Day', 'Kadayawan Festival'],
            Holiday::orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * And the boundary it must not cost: widening to the platform row must
     * not widen to every agency. Sinulog belongs to the other tenant and is
     * invisible here.
     */
    public function test_reads_exclude_another_agencys_rows(): void
    {
        [, $theirs] = $this->calendar();
        $this->withTenant($theirs);

        $this->assertSame(
            ['Bonifacio Day', 'Sinulog'],
            Holiday::orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * With the platform agency as the tenant, `own` and `platform` are the
     * same row, so the scope collapses to the national calendar rather than
     * becoming a view of everything — which is what a naive
     * `orWhere('platform', true)` written on the wrong side would produce.
     */
    public function test_the_platform_tenant_sees_only_the_national_calendar(): void
    {
        $this->calendar();
        $this->withTenant($this->platform());

        $this->assertSame(['Bonifacio Day'], Holiday::orderBy('name')->pluck('name')->all());
    }

    /** Fails closed exactly as AgencyScope does: no tenant is an error, not an empty list. */
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

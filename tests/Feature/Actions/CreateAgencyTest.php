<?php

namespace Tests\Feature\Actions;

use App\Actions\CreateAgency;
use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Turn;
use App\Tenancy\Tenant;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * `CopyDefaultsTest` proves the copy. What is left to prove is the wiring,
 * and it is not a formality: the tenant this action runs under is the
 * *platform* agency, not the one being created.
 */
class CreateAgencyTest extends TestCase
{
    /**
     * The trap this pins.
     *
     * The only caller is `Platform\AgencyController`, reached by a platform
     * user, and `SetTenant` defaults a platform user's tenant to the platform
     * agency itself. `BelongsToAgency` throws `TenantMismatch` on a row whose
     * explicit `agency_id` disagrees with a tenant that is set — so copying
     * without `Tenant::within()` fails on the first shift, and fails only in
     * a request, never in a test that sets no tenant at all. This test sets
     * the tenant the way the request does.
     */
    public function test_a_new_agency_arrives_stocked_with_the_platform_defaults(): void
    {
        $this->defaults();
        $this->withTenant($this->platform());

        $agency = app(CreateAgency::class)->handle(['code' => 'cdh', 'name' => 'City District Hospital']);

        $this->assertSame('cdh', $agency->code);

        $shifts = Shift::query()->withoutGlobalScopes()->where('agency_id', $agency->id)->get();
        $schedule = Schedule::query()->withoutGlobalScopes()->where('agency_id', $agency->id)->sole();
        $turns = Turn::query()->withoutGlobalScopes()->where('schedule_id', $schedule->id)->get();

        $this->assertCount(2, $shifts);
        $this->assertCount(7, $turns);
        $this->assertTrue($turns->every(fn (Turn $turn) => $shifts->contains('id', $turn->shift_id)));
    }

    /**
     * `within()` puts back what it found. The request that created the agency
     * goes on to render the platform's agency index, and a tenant left
     * pointing at the new row would answer every later query for the wrong
     * agency — silently, since a tenant query never errors for being scoped.
     */
    public function test_the_callers_tenant_survives_the_copy(): void
    {
        $this->defaults();
        $platform = $this->platform();
        $this->withTenant($platform);

        app(CreateAgency::class)->handle(['code' => 'cdh', 'name' => 'City District Hospital']);

        $this->assertSame($platform->id, app(Tenant::class)->id());
    }

    /** Nothing lands if anything is refused: an agency with no shifts cannot roster anyone. */
    public function test_the_agency_and_its_defaults_are_one_transaction(): void
    {
        [$working] = $this->defaults();

        // A platform shift the copy cannot write, because the new agency will
        // already hold that name — `shifts` is UNIQUE (agency_id, name).
        // CopyDefaults keeps the agency's own row rather than failing, so the
        // refusal has to come from somewhere the action does not expect: a
        // second agency on the code this one is about to take.
        Agency::factory()->create(['code' => 'cdh']);
        $this->withTenant($this->platform());

        try {
            app(CreateAgency::class)->handle(['code' => 'cdh', 'name' => 'City District Hospital']);
            $this->fail('a duplicate agency code was accepted');
        } catch (QueryException) {
            // Expected: agencies_code_unique.
        }

        $this->assertSame(0, Shift::query()->withoutGlobalScopes()->where('origin_id', $working->id)->count());
    }

    /**
     * The platform fixture `CopyDefaults` copies from. Deliberately small and
     * built here rather than by `DefaultsSeeder`: a test asserting counts
     * should not move every time the product's default set gains a shift.
     *
     * @return array{0: Shift, 1: Shift, 2: Schedule}
     */
    private function defaults(): array
    {
        $platform = $this->platform();

        $working = Shift::factory()->create(['agency_id' => $platform->id, 'name' => 'Standard']);
        $off = Shift::factory()->off()->create(['agency_id' => $platform->id, 'name' => 'Off']);

        $schedule = Schedule::factory()->create([
            'agency_id' => $platform->id,
            'name' => 'Standard week',
            'length' => 7,
        ]);

        foreach (range(0, 6) as $position) {
            Turn::factory()->create([
                'agency_id' => $platform->id,
                'schedule_id' => $schedule->id,
                'shift_id' => $position < 5 ? $working->id : $off->id,
                'position' => $position,
            ]);
        }

        return [$working, $off, $schedule];
    }
}

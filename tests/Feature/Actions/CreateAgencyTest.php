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

    public function test_the_callers_tenant_survives_the_copy(): void
    {
        $this->defaults();
        $platform = $this->platform();
        $this->withTenant($platform);

        app(CreateAgency::class)->handle(['code' => 'cdh', 'name' => 'City District Hospital']);

        $this->assertSame($platform->id, app(Tenant::class)->id());
    }

    public function test_the_agency_and_its_defaults_are_one_transaction(): void
    {
        [$working] = $this->defaults();

        Agency::factory()->create(['code' => 'cdh']);
        $this->withTenant($this->platform());

        try {
            app(CreateAgency::class)->handle(['code' => 'cdh', 'name' => 'City District Hospital']);
            $this->fail('a duplicate agency code was accepted');
        } catch (QueryException) {
        }

        $this->assertSame(0, Shift::query()->withoutGlobalScopes()->where('origin_id', $working->id)->count());
    }

    /** @return array{0: Shift, 1: Shift, 2: Schedule} */
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

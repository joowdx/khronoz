<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\CopyDefaults;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Turn;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Smoke cover for the defaults list: that it can see platform rows at all —
 * `Shift` and `Schedule` read under the plain `AgencyScope`, so this is the
 * one screen that has to drop it — and that scheduling.view gates it.
 */
class DefaultControllerTest extends TestCase
{
    public function test_viewing_requires_scheduling_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewTerminals);

        $this->get(route('defaults.index'))->assertForbidden();
    }

    public function test_the_index_shows_each_default_and_what_this_agency_has_of_it(): void
    {
        $platform = $this->platform();

        $working = Shift::factory()->create(['agency_id' => $platform->id, 'name' => 'Standard']);
        $off = Shift::factory()->off()->create(['agency_id' => $platform->id, 'name' => 'Off']);
        $remote = Shift::factory()->remote()->create(['agency_id' => $platform->id, 'name' => 'Remote']);

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

        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        app(CopyDefaults::class)->handle($agency);

        // One copy is then changed, so the list has a row to offer Refresh on.
        Shift::query()->where('origin_id', $working->id)->sole()->update(['required' => 300]);

        // …and one default is dropped from the copy altogether, so a "not
        // copied" row is on the page too.
        Shift::query()->where('origin_id', $remote->id)->sole()->delete();

        $this->actingAsAgency($agency, Permission::ViewScheduling);

        $this->get(route('defaults.index'))->assertInertia(
            fn (Assert $page) => $page
                ->component('defaults/index')
                ->has('shifts', 3)
                ->has('schedules', 1)
                // Ordered by name: Off, Remote, Standard.
                ->where('shifts.0.default.name', 'Off')
                ->where('shifts.0.linked', true)
                ->where('shifts.0.differs', false)
                ->where('shifts.1.default.name', 'Remote')
                ->where('shifts.1.copy', null)
                ->where('shifts.1.linked', false)
                ->where('shifts.2.default.name', 'Standard')
                ->where('shifts.2.linked', true)
                ->where('shifts.2.differs', true)
                ->where('schedules.0.default.name', 'Standard week')
                ->where('schedules.0.linked', true)
                ->where('schedules.0.differs', false)
                ->has('schedules.0.default.turns', 7)
                ->has('schedules.0.copy.turns', 7)
        );
    }

    public function test_refreshing_a_schedule_rewrites_its_turns_to_this_agencys_own_shifts(): void
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

        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        app(CopyDefaults::class)->handle($agency);

        // The platform moves on: Saturday becomes a working day.
        Turn::withoutGlobalScopes()
            ->where('schedule_id', $schedule->id)
            ->where('position', 5)
            ->sole()
            ->update(['shift_id' => $working->id]);

        $copy = Schedule::query()->sole();

        $this->actingAsAgency($agency, Permission::ManageScheduling);

        $this->post(route('defaults.refresh'), ['type' => 'schedule', 'id' => $copy->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $refreshed = Schedule::query()->with('turns.shift')->sole();
        $ownShifts = Shift::query()->pluck('id')->all();

        $this->assertSame(
            ['Standard', 'Standard', 'Standard', 'Standard', 'Standard', 'Standard', 'Off'],
            $refreshed->turns->sortBy('position')->map(fn (Turn $turn) => $turn->shift->name)->values()->all(),
        );

        foreach ($refreshed->turns as $turn) {
            $this->assertContains($turn->shift_id, $ownShifts, 'a refreshed turn left the agency');
            $this->assertNotSame($working->id, $turn->shift_id);
            $this->assertNotSame($off->id, $turn->shift_id);
        }
    }
}
/** @return void */

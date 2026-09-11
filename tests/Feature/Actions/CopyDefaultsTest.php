<?php

namespace Tests\Feature\Actions;

use App\Actions\CopyDefaults;
use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Turn;
use Tests\TestCase;

/**
 * Smoke cover for the two things about this action that are not obvious from
 * reading it: the remap, and idempotence. The rest of the matrix — name
 * collisions, a fallback shift, a default added after the first copy — is a
 * later hardening wave's.
 */
class CopyDefaultsTest extends TestCase
{
    /**
     * The remap is the whole point.
     *
     * `turns` carries a paired FK `(shift_id, agency_id) REFERENCES shifts
     * (id, agency_id)`, so an agency's turn *cannot* name a platform shift —
     * a copy that forgot to remap would be refused by the database rather
     * than merely wrong. What this proves is the positive: every turn of the
     * copied schedule points at the shift this agency now owns, in the
     * order the origin had them, and every copy carries `origin_id` back.
     */
    public function test_a_copy_carries_its_origin_and_its_turns_point_at_this_agencys_own_shifts(): void
    {
        [$working, $off, $default] = $this->defaults();

        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        $summary = app(CopyDefaults::class)->handle($agency);

        $this->assertSame(2, $summary['shifts']);
        $this->assertSame(1, $summary['schedules']);
        $this->assertSame([], $summary['kept']);

        $shifts = Shift::query()->get()->keyBy('name');

        $this->assertCount(2, $shifts);
        $this->assertSame($working->id, $shifts->get('Standard')?->origin_id);
        $this->assertSame($off->id, $shifts->get('Off')?->origin_id);

        $copy = Schedule::query()->with('turns.shift')->sole();

        $this->assertSame($default->id, $copy->origin_id);
        $this->assertSame($agency->id, $copy->agency_id);
        $this->assertCount(7, $copy->turns);

        foreach ($copy->turns as $turn) {
            $this->assertSame($agency->id, $turn->agency_id);
            $this->assertNotSame($working->id, $turn->shift_id, 'a turn still points at the platform shift');
            $this->assertNotSame($off->id, $turn->shift_id, 'a turn still points at the platform shift');
            $this->assertTrue($shifts->contains('id', $turn->shift_id));
        }

        $this->assertSame(
            ['Standard', 'Standard', 'Standard', 'Standard', 'Standard', 'Off', 'Off'],
            $copy->turns->sortBy('position')->map(fn (Turn $turn) => $turn->shift->name)->values()->all(),
        );
    }

    /** Copying twice is copying once: `origin_id` is what makes the second run a no-op. */
    public function test_copying_twice_copies_nothing_the_second_time(): void
    {
        $this->defaults();

        $agency = Agency::factory()->create();
        $this->withTenant($agency);

        app(CopyDefaults::class)->handle($agency);
        $again = app(CopyDefaults::class)->handle($agency);

        $this->assertSame(0, $again['shifts']);
        $this->assertSame(0, $again['schedules']);

        $this->assertSame(2, Shift::query()->count());
        $this->assertSame(1, Schedule::query()->count());
        $this->assertSame(7, Turn::query()->count());
    }

    /**
     * Two platform shifts and one seven-day schedule using both — the shape
     * `DefaultsSeeder` publishes, written here so the test owns its data.
     *
     * Built before any tenant is set: `BelongsToAgency` refuses an explicit
     * `agency_id` that disagrees with a tenant already in force, so a
     * platform row cannot be written from inside an agency.
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

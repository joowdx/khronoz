<?php

namespace Tests\Feature\Actions;

use App\Actions\CopyDefaults;
use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Turn;
use Tests\TestCase;

/**
 * Smoke cover for the two things about this action that are not obvious from reading it: the remap,
 * and idempotence.
 */
class CopyDefaultsTest extends TestCase
{
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

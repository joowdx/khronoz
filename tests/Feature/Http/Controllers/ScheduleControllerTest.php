<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Turn;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduleControllerTest extends TestCase
{
    public function test_viewing_requires_scheduling_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewCalendar);

        $this->get(route('schedules.index'))->assertForbidden();
    }

    public function test_creating_a_schedule_writes_its_turns_in_position_order(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageScheduling);

        $morning = Shift::factory()->create(['agency_id' => $agency->id, 'name' => 'Morning']);
        $off = Shift::factory()->off()->create(['agency_id' => $agency->id, 'name' => 'Off']);

        $this->post(route('schedules.store'), [
            'name' => 'Rotation',
            'length' => 3,
            'fallback_shift_id' => $morning->id,
            'turns' => [$morning->id, $morning->id, $off->id],
        ])->assertRedirect(route('schedules.index'))->assertSessionHasNoErrors();

        $schedule = Schedule::query()->sole();

        $this->assertSame('Rotation', $schedule->name);
        $this->assertSame(3, $schedule->length);
        $this->assertSame($morning->id, $schedule->fallback_shift_id);

        $this->assertSame(
            [[0, $morning->id], [1, $morning->id], [2, $off->id]],
            Turn::query()
                ->where('schedule_id', $schedule->id)
                ->orderBy('position')
                ->get()
                ->map(fn (Turn $turn): array => [$turn->position, $turn->shift_id])
                ->all(),
        );

        $this->get(route('schedules.index'))->assertInertia(
            fn (Assert $page) => $page
                ->component('schedules/index')
                ->has('schedules', 1)
                ->where('schedules.0.name', 'Rotation')
                ->has('schedules.0.turns', 3)
        );
    }
}

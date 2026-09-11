<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Schedule;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Smoke cover for the teams vertical: it writes a `(name, schedule, anchor)`
 * row and it is behind the scheduling permission. Scoping, prop shape, the
 * 23001 on a team whose rosters still name it, and the validation matrix are a
 * later hardening wave's.
 */
class TeamControllerTest extends TestCase
{
    public function test_viewing_requires_scheduling_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewCalendar);

        $this->get(route('teams.index'))->assertForbidden();
    }

    public function test_creating_a_team_names_a_schedule_and_an_anchor(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ManageScheduling);

        $schedule = Schedule::factory()->withTurns()->create([
            'agency_id' => $agency->id,
            'name' => 'Rotation',
            'length' => 21,
        ]);

        $this->post(route('teams.store'), [
            'name' => 'Team A',
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-07',
        ])->assertRedirect(route('teams.index'))->assertSessionHasNoErrors();

        $team = Team::query()->sole();

        $this->assertSame('Team A', $team->name);
        $this->assertSame($schedule->id, $team->schedule_id);
        $this->assertSame('2026-09-07', $team->anchor->toDateString());

        // The redirect's own destination, rendered: `TeamResource` resolves
        // its schedule through `ScheduleResource`, which resolves that
        // schedule's turns in turn, and a relation the index forgot to load
        // surfaces as "Not a valid Inertia response" rather than as anything
        // naming the column.
        $this->get(route('teams.index'))->assertInertia(
            fn (Assert $page) => $page
                ->component('teams/index')
                ->has('teams', 1)
                ->where('teams.0.name', 'Team A')
                ->where('teams.0.schedule.name', 'Rotation')
                ->where('teams.0.people_count', 0)
        );
    }
}

<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Team;
use App\Models\Turn;
use App\Models\Workgroup;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RosterControllerTest extends TestCase
{
    public function test_viewing_requires_scheduling_view(): void
    {
        $this->actingAsAgency(Agency::factory()->create(), Permission::ViewCalendar);

        $this->get(route('rosters.index'))->assertForbidden();
    }

    /**
     * The grid's one load-bearing projection: a three-shift rotation whose
     * night turn crosses midnight, drawn across a month.
     */
    public function test_the_grid_projects_a_rotation_across_the_month(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewScheduling);

        $workgroup = Workgroup::factory()->create(['agency_id' => $agency->id, 'name' => 'Nursing Service']);

        $morning = $this->shift($agency, 'Morning', '06:00', '14:00', 1);
        $night = $this->shift($agency, 'Night', '22:00', '30:00', 8);
        $off = Shift::factory()->off()->create(['agency_id' => $agency->id, 'name' => 'Rest Day', 'color' => 5]);

        // Four days on mornings, two off, four on nights: enough for one band.
        $schedule = $this->schedule($agency, 'Rotation', [
            $morning, $morning, $morning, $morning, $off, $off, $night, $night, $night, $night,
        ]);

        $team = Team::factory()->create([
            'agency_id' => $agency->id,
            'name' => 'A',
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-01',
        ]);

        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'workgroup_id' => $workgroup->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);
        Roster::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'team_id' => $team->id,
            'anchor' => '2026-09-01',
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $this->get(route('rosters.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page
                ->component('rosters/index')
                ->has('days', 30)
                ->where('days.0.date', '2026-09-01')
                ->has('groups', 1)
                ->where('groups.0.name', 'Nursing Service, Team A')
                ->has('groups.0.rows', 1)
                // 1 Sep is cycle position 0 — a morning.
                ->where('groups.0.rows.0.cells.0.kind', 'shift')
                ->where('groups.0.rows.0.cells.0.letter', 'M')
                // 5 and 6 Sep are the rest turns.
                ->where('groups.0.rows.0.cells.4.kind', 'off')
                // 7 Sep opens the night run, which is one band of four days.
                ->where('groups.0.rows.0.cells.6.letter', 'N')
                ->has('groups.0.rows.0.bands', 3)
                ->where('groups.0.rows.0.bands.0.start', 6)
                ->where('groups.0.rows.0.bands.0.days', 4)
                ->where('groups.0.rows.0.bands.0.clipped', false)
        );
    }

    public function test_somebody_no_roster_covers_is_listed_rather_than_drawn(): void
    {
        $agency = Agency::factory()->create();
        $this->actingAsAgency($agency, Permission::ViewScheduling);

        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        Deployment::factory()->create([
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $this->get(route('rosters.index', ['month' => '2026-09']))->assertInertia(
            fn (Assert $page) => $page
                ->component('rosters/index')
                ->has('groups', 0)
                ->has('unrostered', 1)
                ->where('unrostered.0.id', $employee->id)
        );
    }

    private function shift(Agency $agency, string $name, string $in, string $out, int $color): Shift
    {
        return Shift::factory()->create([
            'agency_id' => $agency->id,
            'name' => $name,
            'slots' => [['in' => $in, 'out' => $out, 'grace' => 0, 'window' => [-120, 120]]],
            'required' => 480,
            'flex' => 0,
            'color' => $color,
        ]);
    }

    /** @param  array<int, Shift>  $shifts */
    private function schedule(Agency $agency, string $name, array $shifts): Schedule
    {
        return DB::transaction(function () use ($agency, $name, $shifts): Schedule {
            $schedule = Schedule::factory()->create([
                'agency_id' => $agency->id,
                'name' => $name,
                'length' => count($shifts),
            ]);

            foreach ($shifts as $position => $shift) {
                Turn::factory()->create([
                    'agency_id' => $agency->id,
                    'schedule_id' => $schedule->id,
                    'shift_id' => $shift->id,
                    'position' => $position,
                ]);
            }

            return $schedule;
        });
    }
}

<?php

namespace Tests\Feature\Models;

use App\Models\Employee;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RosterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function rosterRow(Roster $like, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $like->agency_id,
            'employee_id' => $like->employee_id,
            'schedule_id' => $like->schedule_id,
            'team_id' => null,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => null,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_roster_needs_an_agency(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('rosters')->insert(
            $this->rosterRow($roster, ['agency_id' => null])
        ));
    }

    public function test_anchor_is_required(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('rosters')->insert(
            $this->rosterRow($roster, ['anchor' => null])
        ));
    }

    public function test_starts_is_required(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('rosters')->insert(
            $this->rosterRow($roster, ['starts' => null])
        ));
    }

    public function test_employee_id_is_required(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('rosters')->insert(
            $this->rosterRow($roster, ['employee_id' => null])
        ));
    }

    public function test_schedule_id_is_required(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('rosters')->insert(
            $this->rosterRow($roster, ['schedule_id' => null])
        ));
    }

    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'rosters_id_agency_id_unique'"));
    }

    public function test_employee_must_share_the_rosters_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Roster::factory()->create(['employee_id' => $employee->id]));
    }

    public function test_employee_with_a_roster_cannot_be_hard_deleted(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $roster->employee_id)->delete());
    }

    public function test_schedule_must_share_the_rosters_agency(): void
    {
        $foreign = Schedule::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Roster::factory()->create(['schedule_id' => $foreign->id]));
    }

    public function test_schedule_with_a_roster_cannot_be_deleted(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('schedules')->where('id', $roster->schedule_id)->delete());
    }

    public function test_team_must_share_the_rosters_agency(): void
    {
        $foreign = Team::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Roster::factory()->create(['team_id' => $foreign->id]));
    }

    public function test_team_with_members_cannot_be_deleted(): void
    {
        $team = Team::factory()->create();
        Roster::factory()->fromTeam($team)->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('teams')->where('id', $team->id)->delete());
    }

    public function test_an_ad_hoc_roster_belongs_to_no_team(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseHas('rosters', ['id' => $roster->id, 'team_id' => null]);
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $this->assertDatabaseRefuses('23514', fn () => Roster::factory()->create([
            'starts' => '2026-01-10',
            'ends' => '2026-01-09',
        ]));

        $sameDay = Roster::factory()->create(['starts' => '2026-01-10', 'ends' => '2026-01-10']);
        $this->assertDatabaseHas('rosters', ['id' => $sameDay->id]);
    }

    public function test_closed_ends_on_or_after_a_start_date_the_caller_overrides(): void
    {
        $starts = CarbonImmutable::today()->addYears(5)->toDateString();

        $roster = Roster::factory()->closed()->create(['starts' => $starts]);

        $this->assertGreaterThanOrEqual($starts, $roster->ends->toDateString());
    }

    public function test_rosters_for_one_employee_cannot_overlap(): void
    {
        $standing = Roster::factory()->create(['starts' => '2026-01-01', 'ends' => null]);

        // A second open roster: two unbounded ranges always overlap, which is
        // where "at most one open roster" comes from.
        $this->assertDatabaseRefuses('23P01', fn () => Roster::factory()->create([
            'agency_id' => $standing->agency_id,
            'employee_id' => $standing->employee_id,
            'schedule_id' => $standing->schedule_id,
            'starts' => '2026-06-01',
            'ends' => null,
        ]));

        DB::table('rosters')->where('id', $standing->id)->update(['ends' => '2026-05-31']);

        // The exact day it ended is still occupied by it: daterange '[]' is
        // inclusive of both bounds.
        $this->assertDatabaseRefuses('23P01', fn () => Roster::factory()->create([
            'agency_id' => $standing->agency_id,
            'employee_id' => $standing->employee_id,
            'schedule_id' => $standing->schedule_id,
            'starts' => '2026-05-31',
            'ends' => null,
        ]));

        // The day after: accepted, which is the override handover.
        $override = Roster::factory()->create([
            'agency_id' => $standing->agency_id,
            'employee_id' => $standing->employee_id,
            'schedule_id' => $standing->schedule_id,
            'starts' => '2026-06-01',
            'ends' => '2026-06-07',
        ]);
        $this->assertDatabaseHas('rosters', ['id' => $override->id]);

        // The identical range for a different employee: accepted.
        $other = Roster::factory()->create(['starts' => '2026-01-01', 'ends' => null]);
        $this->assertDatabaseHas('rosters', ['id' => $other->id]);
    }

    public function test_a_rosters_schedule_and_anchor_may_diverge_from_its_teams(): void
    {
        $team = Team::factory()->create(['anchor' => '2026-09-07']);
        $elsewhere = Schedule::factory()->create(['agency_id' => $team->agency_id]);

        $slid = Roster::factory()->fromTeam($team)->create(['anchor' => '2026-09-08']);
        $reassigned = Roster::factory()->fromTeam($team)->create(['schedule_id' => $elsewhere->id]);

        $this->assertDatabaseHas('rosters', ['id' => $slid->id, 'team_id' => $team->id, 'anchor' => '2026-09-08']);
        $this->assertDatabaseHas('rosters', ['id' => $reassigned->id, 'team_id' => $team->id, 'schedule_id' => $elsewhere->id]);
    }
}

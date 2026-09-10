<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamTest extends TestCase
{
    /** @return array<string, mixed> */
    private function teamRow(string $agency, string $schedule, array $overrides = []): array
    {
        return [
            'id' => (string) Str::ulid(),
            'agency_id' => $agency,
            'name' => 'Team '.Str::random(4),
            'schedule_id' => $schedule,
            'anchor' => '2026-09-07',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
    }

    public function test_team_needs_an_agency(): void
    {
        $schedule = Schedule::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('teams')->insert(
            $this->teamRow($schedule->agency_id, $schedule->id, ['agency_id' => null])
        ));
    }

    /**
     * anchor is NOT NULL, and it is load-bearing rather than merely required:
     * resolution is `position = (D - anchor) mod length`, so a null anchor
     * would make every date resolve to nothing while the team looked
     * perfectly well-formed.
     */
    public function test_anchor_is_required(): void
    {
        $schedule = Schedule::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('teams')->insert(
            $this->teamRow($schedule->agency_id, $schedule->id, ['anchor' => null])
        ));
    }

    public function test_agency_with_teams_cannot_be_deleted(): void
    {
        $team = Team::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('agencies')->where('id', $team->agency_id)->delete());
    }

    public function test_name_is_unique_within_an_agency(): void
    {
        $team = Team::factory()->create();

        $this->assertDatabaseRefuses('23505', fn () => DB::table('teams')->insert(
            $this->teamRow($team->agency_id, $team->schedule_id, ['name' => $team->name])
        ));

        // The same name under a different agency: accepted.
        $elsewhere = Team::factory()->create(['name' => $team->name]);
        $this->assertDatabaseHas('teams', ['id' => $elsewhere->id, 'name' => $team->name]);
    }

    /** Ruling P4: the primary key masks the pair, so assert the catalog — rosters.team_id pairs against it. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'teams_id_agency_id_unique'"));
    }

    /** teams_schedule_id_agency_id_foreign, insert side: a schedule of another agency. */
    public function test_schedule_must_share_the_teams_agency(): void
    {
        $foreign = Schedule::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Team::factory()->create(['schedule_id' => $foreign->id]));
    }

    /** Same FK, delete side. */
    public function test_schedule_used_by_a_team_cannot_be_deleted(): void
    {
        $team = Team::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('schedules')->where('id', $team->schedule_id)->delete());
    }

    /**
     * agency_not_platform (P0001). This is where a team differs from a shift
     * or a schedule: the platform agency owns the *defaults*, which are
     * templates, but a team is a cohort of real people and nothing
     * operational hangs under the platform row (07-constraints.md).
     */
    public function test_the_platform_agency_has_no_teams(): void
    {
        $platform = $this->platform();
        $schedule = Schedule::factory()->create(['agency_id' => $platform->id]);

        $this->assertDatabaseRefuses('P0001', fn () => DB::table('teams')->insert(
            $this->teamRow($platform->id, $schedule->id)
        ));
    }

    /**
     * A team's membership is the rosters carrying its id, and there is no
     * pivot table to add. Two properties follow for free and are worth
     * pinning, because a membership table would have needed extra
     * constraints for both: "who was on it in March" is answerable from the
     * rosters' own ranges, and one employee cannot be on two teams at once,
     * since rosters_no_overlap already gives each employee one roster per date.
     */
    public function test_membership_is_the_rosters_carrying_the_team_id(): void
    {
        $team = Team::factory()->create();
        $one = Roster::factory()->fromTeam($team)->create(['starts' => '2026-01-01', 'ends' => '2026-06-30']);
        $two = Roster::factory()->fromTeam($team)->create(['starts' => '2026-01-01', 'ends' => null]);

        $this->assertSame(2, DB::table('rosters')->where('team_id', $team->id)->count());

        // A second team for the same employee over the same dates is exactly
        // what rosters_no_overlap forbids — no membership constraint needed.
        $other = Team::factory()->create(['agency_id' => $team->agency_id]);

        $this->assertDatabaseRefuses('23P01', fn () => Roster::factory()->fromTeam($other)->create([
            'employee_id' => $two->employee_id,
            'starts' => '2026-03-01',
            'ends' => null,
        ]));

        // Ending the first roster frees the employee to join the other team.
        DB::table('rosters')->where('id', $two->id)->update(['ends' => '2026-02-28']);
        $moved = Roster::factory()->fromTeam($other)->create([
            'employee_id' => $two->employee_id,
            'starts' => '2026-03-01',
            'ends' => null,
        ]);

        $this->assertDatabaseHas('rosters', ['id' => $moved->id, 'team_id' => $other->id]);
        $this->assertDatabaseHas('rosters', ['id' => $one->id, 'team_id' => $team->id]);
    }

    /**
     * A team is a standing definition and has no starts/ends. Asserted
     * against the catalog rather than by writing a row, because the absence
     * of a column is not something an INSERT can demonstrate — and this is a
     * shape decision worth guarding: date-ranging a team would duplicate what
     * the rosters already answer and make "who was on it in March" ambiguous.
     */
    public function test_a_team_carries_no_date_range(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_name', 'teams')
            ->pluck('column_name')
            ->all();

        $this->assertNotContains('starts', $columns);
        $this->assertNotContains('ends', $columns);
    }
}

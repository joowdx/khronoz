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

/**
 * rosters_agency_id_foreign is untested on both sides for the reason Ruling
 * P5 gives on deployments: the paired FKs require their parents to carry a
 * valid agency_id, so no row exists where this FK alone fails. Its delete
 * side is covered transitively by the employees and schedules tests.
 *
 * No agency_not_platform test either, for the reason deployments has none: it
 * would be unreachable, since a roster needs an employee and `employees`
 * refuses the platform agency already.
 */
class RosterTest extends TestCase
{
    /** @return array<string, mixed> */
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

    /**
     * anchor is NOT NULL for the reason teams.anchor is: resolution is
     * `position = (D - anchor) mod length`, so a null anchor resolves every
     * date to nothing while the row looks well-formed.
     */
    public function test_anchor_is_required(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('rosters')->insert(
            $this->rosterRow($roster, ['anchor' => null])
        ));
    }

    /**
     * starts is NOT NULL for the reason deployments.starts is: a null makes
     * rosters_dates_ordered evaluate to NULL, which a CHECK accepts, and
     * yields a daterange with an infinite lower bound that the exclusion
     * constraint indexes perfectly happily.
     */
    public function test_starts_is_required(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23502', fn () => DB::table('rosters')->insert(
            $this->rosterRow($roster, ['starts' => null])
        ));
    }

    /**
     * employee_id is NOT NULL, and one nullable column would defeat two
     * constraints at once: MATCH SIMPLE skips a paired FK entirely once a
     * referencing column is null, so the tenancy guarantee would evaporate,
     * and `null = null` is NULL rather than true, so the row would also
     * escape rosters_no_overlap and allow unlimited overlapping rosters.
     */
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

    /** Ruling P4: the primary key masks the pair, so assert the catalog. */
    public function test_id_and_agency_id_pair_is_declared_unique(): void
    {
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'rosters_id_agency_id_unique'"));
    }

    /** rosters_employee_id_agency_id_foreign, insert side: an employee of another agency. */
    public function test_employee_must_share_the_rosters_agency(): void
    {
        $employee = Employee::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Roster::factory()->create(['employee_id' => $employee->id]));
    }

    /**
     * Same FK, delete side. A raw DELETE, not $employee->delete(): employees
     * are soft deleted, so the Eloquent call is an UPDATE the FK never sees
     * and the test would assert nothing while passing.
     */
    public function test_employee_with_a_roster_cannot_be_hard_deleted(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('employees')->where('id', $roster->employee_id)->delete());
    }

    /** rosters_schedule_id_agency_id_foreign, insert side. */
    public function test_schedule_must_share_the_rosters_agency(): void
    {
        $foreign = Schedule::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Roster::factory()->create(['schedule_id' => $foreign->id]));
    }

    /** Same FK, delete side: a schedule someone is rostered onto cannot be removed. */
    public function test_schedule_with_a_roster_cannot_be_deleted(): void
    {
        $roster = Roster::factory()->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('schedules')->where('id', $roster->schedule_id)->delete());
    }

    /** rosters_team_id_agency_id_foreign, insert side: a team of another agency. */
    public function test_team_must_share_the_rosters_agency(): void
    {
        $foreign = Team::factory()->create();

        $this->assertDatabaseRefuses('23503', fn () => Roster::factory()->create(['team_id' => $foreign->id]));
    }

    /** Same FK, delete side: a team with members cannot be dropped out from under them. */
    public function test_team_with_members_cannot_be_deleted(): void
    {
        $team = Team::factory()->create();
        Roster::factory()->fromTeam($team)->create();

        $this->assertDatabaseRefuses('23001', fn () => DB::table('teams')->where('id', $team->id)->delete());
    }

    /** team_id is nullable: an ad-hoc set is a tag filter plus select-all, and belongs to no cohort. */
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

    /**
     * The factory's own bug rather than the schema's, the twin of
     * DeploymentTest's. closed() computed `ends` from the *definition's*
     * default `starts` — a state closure only ever sees the attributes
     * accumulated before it, and create([...]) is appended as the last state —
     * so overriding `starts` alone put `ends` before it and
     * rosters_dates_ordered refused the row with 23514.
     *
     * Five years out rather than a literal date: the pre-fix ceiling was
     * `now + 1 year`, so a nearer literal would land before the override only
     * *sometimes*, making the failure a coin toss rather than a proof.
     */
    public function test_closed_ends_on_or_after_a_start_date_the_caller_overrides(): void
    {
        $starts = CarbonImmutable::today()->addYears(5)->toDateString();

        $roster = Roster::factory()->closed()->create(['starts' => $starts]);

        $this->assertGreaterThanOrEqual($starts, $roster->ends->toDateString());
    }

    /**
     * rosters_no_overlap: one roster per employee per date (rule 1). Three
     * assertions, each a different way the same constraint bites.
     *
     * This is also what makes a one-week override a one-week roster (rule 3):
     * the constraint forces the standing roster to be ended first, which is
     * the right paper trail rather than a silent replacement.
     */
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

    /**
     * The consistency trigger that is **deliberately absent**
     * (07-constraints.md). A roster's schedule_id and anchor may differ from
     * those of the team it names, and this test exists so that adding a
     * trigger to "fix" the divergence fails loudly rather than looking like a
     * tightening.
     *
     * Why it must stay legal: team_id records where the assignment came from,
     * not a rule about what it produced. An agency can slide one nurse's
     * anchor by a day without taking her off the cohort, and re-anchoring a
     * team re-issues rosters rather than rewriting them — a trigger holding
     * the two equal would make re-anchoring rewrite history, which is the
     * opposite of what the ranges are for. Resolution reads the roster and
     * never the team.
     */
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

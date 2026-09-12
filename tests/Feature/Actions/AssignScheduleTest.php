<?php

namespace Tests\Feature\Actions;

use App\Actions\AssignSchedule;
use App\Actions\AssignSchedules;
use App\Actions\AssignTeam;
use App\Jobs\FanOutRecompute;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssignScheduleTest extends TestCase
{
    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::factory()->create();
        $this->withTenant($this->agency);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create(['agency_id' => $this->agency->id]);
    }

    private function schedule(int $length = 7): Schedule
    {
        return Schedule::factory()->withTurns()->create(['agency_id' => $this->agency->id, 'length' => $length]);
    }

    public function test_assigns_one_roster_with_the_anchor_it_was_given(): void
    {
        $employee = $this->employee();
        $schedule = $this->schedule();

        $roster = app(AssignSchedule::class)->handle(
            $employee, $schedule, Carbon::parse('2026-09-07'), Carbon::parse('2026-10-01')
        );

        $this->assertSame($schedule->id, $roster->schedule_id);
        $this->assertSame('2026-09-07', $roster->anchor->toDateString());
        $this->assertSame('2026-10-01', $roster->starts->toDateString());
        $this->assertNull($roster->ends);
        $this->assertNull($roster->team_id, 'an ad-hoc assignment belongs to no cohort');
    }

    public function test_the_anchor_is_independent_of_the_start_date(): void
    {
        $roster = app(AssignSchedule::class)->handle(
            $this->employee(), $this->schedule(21), Carbon::parse('2026-01-01'), Carbon::parse('2026-06-15')
        );

        $this->assertSame('2026-01-01', $roster->anchor->toDateString());
        $this->assertSame('2026-06-15', $roster->starts->toDateString());
        $this->assertTrue($roster->anchor->lt($roster->starts));
    }

    public function test_closes_the_covering_roster_the_day_before_the_new_one_starts(): void
    {
        $employee = $this->employee();
        $first = app(AssignSchedule::class)->handle(
            $employee, $this->schedule(), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01')
        );

        $second = app(AssignSchedule::class)->handle(
            $employee->fresh(), $this->schedule(), Carbon::parse('2026-03-01'), Carbon::parse('2026-03-01')
        );

        $this->assertSame('2026-02-28', $first->fresh()->ends->toDateString());
        $this->assertNull($second->ends);
        $this->assertSame(2, DB::table('rosters')->where('employee_id', $employee->id)->count());
    }

    public function test_a_one_week_override_is_a_one_week_roster(): void
    {
        $employee = $this->employee();
        app(AssignSchedule::class)->handle(
            $employee, $this->schedule(), Carbon::parse('2026-01-05'), Carbon::parse('2026-01-05')
        );

        $override = app(AssignSchedule::class)->handle(
            $employee->fresh(), $this->schedule(), Carbon::parse('2026-03-02'), Carbon::parse('2026-03-02'), Carbon::parse('2026-03-08')
        );

        $this->assertSame('2026-03-08', $override->ends->toDateString());
        $this->assertSame(2, DB::table('rosters')->where('employee_id', $employee->id)->count());
    }

    public function test_backdating_splits_the_roster_that_covers_the_new_start(): void
    {
        $employee = $this->employee();
        $standing = Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'starts' => '2026-01-01',
            'ends' => null,
        ]);

        $override = app(AssignSchedule::class)->handle(
            $employee, $this->schedule(), Carbon::parse('2026-03-01'), Carbon::parse('2026-03-01')
        );

        $this->assertSame('2026-02-28', $standing->fresh()->ends->toDateString());
        $this->assertSame('2026-03-01', $override->starts->toDateString());
    }

    public function test_refuses_an_open_assignment_that_runs_into_a_later_roster(): void
    {
        $employee = $this->employee();
        Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'starts' => '2026-06-01',
            'ends' => null,
        ]);

        $this->assertDatabaseRefuses('23P01', fn () => app(AssignSchedule::class)->handle(
            $employee, $this->schedule(), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01')
        ));
    }

    public function test_a_refused_assignment_writes_nothing(): void
    {
        // Built before the tenant is set, since BelongsToAgency refuses to
        // create a row for any agency but the current one — the protection
        // working, not a test obstacle.
        $foreign = Schedule::withoutEvents(fn () => Schedule::factory()->create(['agency_id' => Agency::factory()->create()->id]));
        $employee = $this->employee();

        $before = DB::table('rosters')->count();

        $this->assertDatabaseRefuses('23503', fn () => app(AssignSchedule::class)->handle(
            $employee, $foreign, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01')
        ));

        $this->assertSame($before, DB::table('rosters')->count());
    }

    public function test_bulk_assignment_writes_one_roster_each(): void
    {
        $employees = collect([$this->employee(), $this->employee(), $this->employee()]);
        $schedule = $this->schedule();

        $rosters = app(AssignSchedules::class)->handle(
            $employees, $schedule, Carbon::parse('2026-09-07'), Carbon::parse('2026-09-07')
        );

        $this->assertCount(3, $rosters);
        $this->assertSame(3, DB::table('rosters')->where('schedule_id', $schedule->id)->count());
        $this->assertSame([null, null, null], $rosters->pluck('team_id')->all());
    }

    public function test_a_bulk_assignment_that_collides_assigns_nobody(): void
    {
        $clean = $this->employee();
        $conflicted = $this->employee();
        Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $conflicted->id,
            'starts' => '2026-06-01',
            'ends' => null,
        ]);

        $before = DB::table('rosters')->count();

        $this->assertDatabaseRefuses('23P01', fn () => app(AssignSchedules::class)->handle(
            collect([$clean, $conflicted]), $this->schedule(), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01')
        ));

        $this->assertSame($before, DB::table('rosters')->count());
        $this->assertSame(0, DB::table('rosters')->where('employee_id', $clean->id)->count());
    }

    public function test_a_team_assignment_copies_the_schedule_and_anchor(): void
    {
        $schedule = $this->schedule(21);
        $team = Team::factory()->on($schedule, '2026-09-07')->create();
        $employees = collect([$this->employee(), $this->employee()]);

        $rosters = app(AssignTeam::class)->handle($employees, $team, Carbon::parse('2026-10-01'));

        foreach ($rosters as $roster) {
            $this->assertSame($team->id, $roster->team_id);
            $this->assertSame($schedule->id, $roster->schedule_id);
            $this->assertSame('2026-09-07', $roster->anchor->toDateString());
            $this->assertSame('2026-10-01', $roster->starts->toDateString());
        }
    }

    public function test_three_teams_on_one_cycle_keep_their_own_anchors(): void
    {
        $schedule = $this->schedule(21);
        $anchors = ['2026-09-07', '2026-09-14', '2026-09-21'];

        foreach ($anchors as $index => $anchor) {
            $team = Team::factory()->on($schedule, $anchor)->create(['name' => 'Team '.$index]);
            $roster = app(AssignTeam::class)->handle(collect([$this->employee()]), $team, Carbon::parse('2026-10-01'))->first();

            $this->assertSame($anchor, $roster->anchor->toDateString());
            $this->assertSame($schedule->id, $roster->schedule_id);
        }

        $this->assertSame(3, DB::table('rosters')->where('schedule_id', $schedule->id)->count());
    }

    public function test_re_anchoring_a_team_re_issues_its_members_rosters(): void
    {
        $schedule = $this->schedule(21);
        $team = Team::factory()->on($schedule, '2026-09-07')->create();
        $employee = $this->employee();

        $before = app(AssignTeam::class)->handle(collect([$employee]), $team, Carbon::parse('2026-10-01'))->first();

        $team->update(['anchor' => '2026-09-14']);
        $after = app(AssignTeam::class)->handle(collect([$employee->fresh()]), $team->fresh(), Carbon::parse('2026-11-01'))->first();

        // The original roster keeps its own anchor and is closed, not rewritten.
        $this->assertSame('2026-09-07', $before->fresh()->anchor->toDateString());
        $this->assertSame('2026-10-31', $before->fresh()->ends->toDateString());

        $this->assertSame('2026-09-14', $after->anchor->toDateString());
        $this->assertSame('2026-11-01', $after->starts->toDateString());
        $this->assertSame(2, DB::table('rosters')->where('employee_id', $employee->id)->count());
    }

    public function test_assigning_a_schedule_queues_a_recompute_from_the_day_it_starts(): void
    {
        $agency = Agency::factory()->create();
        $this->withTenant($agency);
        $employee = Employee::factory()->create(['agency_id' => $agency->id]);
        $schedule = Schedule::factory()->create(['agency_id' => $agency->id]);

        Queue::fake([FanOutRecompute::class]);

        app(AssignSchedule::class)->handle(
            $employee,
            $schedule,
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-20'),
        );

        Queue::assertPushed(
            FanOutRecompute::class,
            fn (FanOutRecompute $job): bool => $job->employeeIds === [$employee->id]
                && $job->from === '2026-09-14'
                && $job->to === null,
        );
    }
}

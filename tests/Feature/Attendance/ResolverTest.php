<?php

namespace Tests\Feature\Attendance;

use App\Attendance\Resolution;
use App\Attendance\Resolver;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Team;
use App\Models\Turn;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Roster resolution: which shift the roster puts on each date (04-scheduling.md, Resolution for
 * employee E on date D, steps 1 to 3). The calendar does not enter here — holidays, suspensions
 * and the compressed-week fallback belong to `Calendar`.
 */
class ResolverTest extends TestCase
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

    /**
     * @return array{employee: Employee, roster: Roster, standard: Shift, off: Shift}
     */
    private function standardWeek(?Employee $employee = null): array
    {
        $employee ??= $this->employee();
        $standard = Shift::factory()->create(['agency_id' => $this->agency->id]);
        $off = Shift::factory()->off()->create(['agency_id' => $this->agency->id]);
        $schedule = Schedule::factory()->withTurns($standard, $off)->create(['agency_id' => $this->agency->id]);
        $roster = Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => null,
        ]);

        return compact('employee', 'roster', 'standard', 'off');
    }

    /** @return array{schedule: Schedule, morning: Shift, afternoon: Shift, night: Shift, off: Shift} */
    private function rotation(): array
    {
        $morning = Shift::factory()->create(['agency_id' => $this->agency->id, 'name' => 'Morning']);
        $afternoon = Shift::factory()->create(['agency_id' => $this->agency->id, 'name' => 'Afternoon']);
        $night = Shift::factory()->create(['agency_id' => $this->agency->id, 'name' => 'Night']);
        $off = Shift::factory()->off()->create(['agency_id' => $this->agency->id]);
        $schedule = Schedule::factory()->create([
            'agency_id' => $this->agency->id,
            'name' => 'Rotation',
            'length' => 21,
        ]);

        $blocks = [
            ...array_fill(0, 5, $morning), $off, $off,
            ...array_fill(0, 5, $afternoon), $off, $off,
            ...array_fill(0, 5, $night), $off, $off,
        ];

        foreach ($blocks as $position => $shift) {
            Turn::factory()->create([
                'agency_id' => $this->agency->id,
                'schedule_id' => $schedule->id,
                'shift_id' => $shift->id,
                'position' => $position,
            ]);
        }

        return compact('schedule', 'morning', 'afternoon', 'night', 'off');
    }

    /**
     * @param  array<string, ?Resolution>  $days
     */
    private function assertShift(array $days, string $date, Roster $roster, int $position, Shift $shift): void
    {
        $this->assertArrayHasKey($date, $days);
        $this->assertInstanceOf(Resolution::class, $days[$date]);
        $this->assertSame($roster->id, $days[$date]->roster->id);
        $this->assertSame($position, $days[$date]->position);
        $this->assertSame($shift->id, $days[$date]->shift->id);
    }

    public function test_a_standard_week_resolves_five_working_days_then_two_off(): void
    {
        ['employee' => $employee, 'roster' => $roster, 'standard' => $standard, 'off' => $off] = $this->standardWeek();

        // Another person on a different shift the same week: resolution is
        // per employee, and a missing employee filter would pick either row.
        $this->standardWeek();

        $days = (new Resolver($employee))->over(
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-13'),
        );

        $this->assertSame([
            '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10',
            '2026-09-11', '2026-09-12', '2026-09-13',
        ], array_keys($days));

        $this->assertShift($days, '2026-09-07', $roster, 0, $standard);
        $this->assertShift($days, '2026-09-08', $roster, 1, $standard);
        $this->assertShift($days, '2026-09-09', $roster, 2, $standard);
        $this->assertShift($days, '2026-09-10', $roster, 3, $standard);
        $this->assertShift($days, '2026-09-11', $roster, 4, $standard);
        $this->assertShift($days, '2026-09-12', $roster, 5, $off);
        $this->assertShift($days, '2026-09-13', $roster, 6, $off);
    }

    public function test_three_hospital_teams_sit_seven_positions_apart_on_one_date(): void
    {
        ['schedule' => $schedule, 'morning' => $morning, 'afternoon' => $afternoon, 'night' => $night] = $this->rotation();

        $teamA = Team::factory()->on($schedule, '2026-09-07')->create();
        $teamB = Team::factory()->on($schedule, '2026-09-14')->create();
        $teamC = Team::factory()->on($schedule, '2026-09-21')->create();

        $employeeA = $this->employee();
        $employeeB = $this->employee();
        $employeeC = $this->employee();

        $rosterA = Roster::factory()->fromTeam($teamA)->create(['employee_id' => $employeeA->id]);
        $rosterB = Roster::factory()->fromTeam($teamB)->create(['employee_id' => $employeeB->id]);
        $rosterC = Roster::factory()->fromTeam($teamC)->create(['employee_id' => $employeeC->id]);

        $date = CarbonImmutable::parse('2026-09-07');

        $this->assertShift(
            (new Resolver($employeeA))->over($date, $date),
            '2026-09-07', $rosterA, 0, $morning,
        );
        $this->assertShift(
            (new Resolver($employeeB))->over($date, $date),
            '2026-09-07', $rosterB, 14, $night,
        );
        $this->assertShift(
            (new Resolver($employeeC))->over($date, $date),
            '2026-09-07', $rosterC, 7, $afternoon,
        );
    }

    public function test_a_date_before_the_anchor_still_resolves_from_the_anchor(): void
    {
        $employee = $this->employee();
        $standard = Shift::factory()->create(['agency_id' => $this->agency->id]);
        $off = Shift::factory()->off()->create(['agency_id' => $this->agency->id]);
        $schedule = Schedule::factory()->withTurns($standard, $off)->create(['agency_id' => $this->agency->id]);
        $roster = Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-01',
            'ends' => null,
        ]);

        $date = CarbonImmutable::parse('2026-09-05');
        $days = (new Resolver($employee))->over($date, $date);

        $this->assertShift($days, '2026-09-05', $roster, 5, $off);
    }

    public function test_a_handover_date_takes_the_second_roster(): void
    {
        $employee = $this->employee();
        $standard = Shift::factory()->create(['agency_id' => $this->agency->id]);
        $off = Shift::factory()->off()->create(['agency_id' => $this->agency->id]);
        $flexi = Shift::factory()->flexible()->create(['agency_id' => $this->agency->id]);
        $week = Schedule::factory()->withTurns($standard, $off)->create(['agency_id' => $this->agency->id]);
        $flexiWeek = Schedule::factory()->withTurns($flexi, $off)->create(['agency_id' => $this->agency->id]);

        $first = Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $week->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => '2026-09-13',
        ]);
        $second = Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $flexiWeek->id,
            'anchor' => '2026-09-14',
            'starts' => '2026-09-14',
            'ends' => null,
        ]);

        $days = (new Resolver($employee))->over(
            CarbonImmutable::parse('2026-09-13'),
            CarbonImmutable::parse('2026-09-14'),
        );

        $this->assertShift($days, '2026-09-13', $first, 6, $off);
        $this->assertShift($days, '2026-09-14', $second, 0, $flexi);
    }

    public function test_a_gap_with_no_roster_returns_null(): void
    {
        $employee = $this->employee();
        $standard = Shift::factory()->create(['agency_id' => $this->agency->id]);
        $off = Shift::factory()->off()->create(['agency_id' => $this->agency->id]);
        $week = Schedule::factory()->withTurns($standard, $off)->create(['agency_id' => $this->agency->id]);

        $first = Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $week->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => '2026-09-10',
        ]);
        $second = Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $week->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-14',
            'ends' => null,
        ]);

        $days = (new Resolver($employee))->over(
            CarbonImmutable::parse('2026-09-10'),
            CarbonImmutable::parse('2026-09-15'),
        );

        $this->assertShift($days, '2026-09-10', $first, 3, $standard);
        $this->assertNull($days['2026-09-11']);
        $this->assertNull($days['2026-09-12']);
        $this->assertNull($days['2026-09-13']);
        $this->assertShift($days, '2026-09-14', $second, 0, $standard);
        $this->assertShift($days, '2026-09-15', $second, 1, $standard);
    }

    public function test_resolving_sixty_days_issues_the_same_queries_as_one(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        $oneDay = CarbonImmutable::parse('2026-09-07');
        $resolver = new Resolver($employee);

        $one = $this->queries(fn () => $resolver->over($oneDay, $oneDay));
        $sixty = $this->queries(fn () => $resolver->over($oneDay, $oneDay->addDays(59)));

        $this->assertSame($one, $sixty);
        $this->assertSame(4, $one, 'rosters + schedule + turns + shifts, once each');
    }

    public function test_from_after_to_returns_an_empty_array(): void
    {
        ['employee' => $employee] = $this->standardWeek();

        $this->assertSame([], (new Resolver($employee))->over(
            CarbonImmutable::parse('2026-09-13'),
            CarbonImmutable::parse('2026-09-07'),
        ));
    }

    public function test_a_missing_turn_is_an_invariant_violation(): void
    {
        $employee = $this->employee();
        $schedule = Schedule::factory()->create(['agency_id' => $this->agency->id, 'length' => 7]);
        Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Schedule {$schedule->id} has no turn at position 0.");

        $date = CarbonImmutable::parse('2026-09-07');
        (new Resolver($employee))->over($date, $date);
    }

    private function queries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}

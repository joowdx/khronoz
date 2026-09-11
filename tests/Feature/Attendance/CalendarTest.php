<?php

namespace Tests\Feature\Attendance;

use App\Attendance\Almanac;
use App\Attendance\Calendar;
use App\Attendance\Day;
use App\Attendance\Resolution;
use App\Attendance\Resolver;
use App\Attendance\Week;
use App\Enums\ExemptionType;
use App\Enums\HolidayType;
use App\Enums\Premium;
use App\Enums\WorkdayStatus;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Holiday;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Suspension;
use App\Models\Turn;
use App\Models\Workgroup;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Apply the calendar to a resolved roster day (05-calendar.md rules 1, 3
 * and 7; 06-attendance.md daily rules 1, 7, 8 and 10). Status, premium,
 * truncation and the compressed-week revert — everything that does not
 * need a punch.
 */
class CalendarTest extends TestCase
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

    /** Agency-wide suspensions reach only the deployed. */
    private function deployed(): Employee
    {
        $employee = $this->employee();
        $workgroup = Workgroup::factory()->create(['agency_id' => $this->agency->id]);
        Deployment::factory()->create([
            'agency_id' => $this->agency->id,
            'workgroup_id' => $workgroup->id,
            'employee_id' => $employee->id,
            'starts' => '2026-01-01',
            'ends' => '2026-12-31',
        ]);

        return $employee;
    }

    /**
     * Standard week, anchored Monday 7 September 2026.
     *
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

    /**
     * CWW Mon–Thu: Long ×4, Off ×3, fallback Standard (04-scheduling.md).
     *
     * @return array{employee: Employee, roster: Roster, long: Shift, off: Shift, standard: Shift}
     */
    private function compressedWeek(?Employee $employee = null): array
    {
        $employee ??= $this->employee();
        $standard = Shift::factory()->create(['agency_id' => $this->agency->id]);
        $long = Shift::factory()->create([
            'agency_id' => $this->agency->id,
            'slots' => [
                ['in' => '07:00', 'out' => '12:00', 'window' => [-240, 180]],
                ['in' => '13:00', 'out' => '18:00', 'window' => [-120, 300]],
            ],
            'required' => 600,
        ]);
        $off = Shift::factory()->off()->create(['agency_id' => $this->agency->id]);
        $schedule = Schedule::factory()->create([
            'agency_id' => $this->agency->id,
            'length' => 7,
            'fallback_shift_id' => $standard->id,
        ]);

        foreach (range(0, 6) as $position) {
            Turn::factory()->create([
                'agency_id' => $this->agency->id,
                'schedule_id' => $schedule->id,
                'shift_id' => $position < 4 ? $long->id : $off->id,
                'position' => $position,
            ]);
        }

        $roster = Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => null,
        ]);

        return compact('employee', 'roster', 'long', 'off', 'standard');
    }

    /**
     * @return array<string, Resolution|null>
     */
    private function resolutions(Employee $employee, CarbonInterface $date): array
    {
        [$monday, $sunday] = Week::bounds($date);
        $resolutions = (new Resolver($employee))->over($monday, $sunday);

        foreach ($resolutions as $resolution) {
            $resolution?->roster->schedule->loadMissing('fallbackShift');
        }

        return $resolutions;
    }

    private function apply(Employee $employee, CarbonInterface $date): Day
    {
        [$monday, $sunday] = Week::bounds($date);
        $calendar = new Calendar(
            Almanac::for($employee, $monday, $sunday),
            new Settings($this->agency),
        );

        return $calendar->apply($this->resolutions($employee, $date), $date);
    }

    /** @param  list<array{slot: int, kind: string, at: mixed, grace: int, window: array{0: int, 1: int}}>  $sides */
    private function assertAt(array $sides, int $index, string $at): void
    {
        $this->assertSame($at, $sides[$index]['at']->format('Y-m-d H:i:s'));
    }

    /**
     * Rule a / decision 63: a missing roster is off with no premium — an
     * absence of data, not a declared rest day. Exemptions still apply.
     */
    public function test_no_resolution_is_off_with_no_premium(): void
    {
        $employee = $this->employee();
        $date = CarbonImmutable::parse('2026-09-09');
        $leave = Exemption::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => $date,
        ]);

        $day = $this->apply($employee, $date);

        $this->assertNull($day->shift);
        $this->assertSame([], $day->sides);
        $this->assertSame(WorkdayStatus::Off, $day->status);
        $this->assertNull($day->premium);
        $this->assertSame($leave->id, $day->exemptionId);
        $this->assertCount(1, $day->excused);
        $this->assertSame('2026-09-09 00:00:00', $day->excused[0][0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 00:00:00', $day->excused[0][1]->format('Y-m-d H:i:s'));
    }

    /**
     * Rule b: holiday on an Off turn of a compressed week. The Off day
     * stays off; every other date after declared_at resolves to Standard.
     * A declaration dated the 10th must not retroject onto the 10th.
     */
    public function test_the_compressed_week_reverts_other_days_to_the_fallback_shift(): void
    {
        ['employee' => $employee, 'long' => $long, 'off' => $off, 'standard' => $standard] = $this->compressedWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-12',
            'type' => HolidayType::Regular,
            'declared_at' => CarbonImmutable::parse('2026-09-10 15:00:00'),
        ]);

        $thursday = $this->apply($employee, CarbonImmutable::parse('2026-09-10'));
        $friday = $this->apply($employee, CarbonImmutable::parse('2026-09-11'));
        $saturday = $this->apply($employee, CarbonImmutable::parse('2026-09-12'));
        $sunday = $this->apply($employee, CarbonImmutable::parse('2026-09-13'));

        $this->assertSame($long->id, $thursday->shift->id);
        $this->assertAt($thursday->sides, 0, '2026-09-10 07:00:00');
        $this->assertNull($thursday->premium);

        $this->assertSame($standard->id, $friday->shift->id);
        $this->assertAt($friday->sides, 0, '2026-09-11 08:00:00');
        $this->assertNull($friday->status);

        $this->assertSame($off->id, $saturday->shift->id);
        $this->assertSame([], $saturday->sides);
        $this->assertSame(WorkdayStatus::Off, $saturday->status);
        $this->assertSame(Premium::Regular, $saturday->premium);

        $this->assertSame($standard->id, $sunday->shift->id);
        $this->assertAt($sunday->sides, 0, '2026-09-13 08:00:00');
    }

    /**
     * Acceptance: a silently absent week is a compressed-week revert that
     * never happens. Name the missing date.
     */
    public function test_apply_requires_the_whole_iso_week(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        $date = CarbonImmutable::parse('2026-09-09');
        $resolutions = $this->resolutions($employee, $date);
        unset($resolutions['2026-09-13']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('2026-09-13');

        [$monday, $sunday] = Week::bounds($date);
        (new Calendar(Almanac::for($employee, $monday, $sunday), new Settings($this->agency)))
            ->apply($resolutions, $date);
    }

    /**
     * Rule c: any holiday with expectsWork() false empties sides. A working
     * holiday alone changes nothing.
     */
    public function test_a_non_working_holiday_removes_the_expectation(): void
    {
        ['employee' => $employee, 'standard' => $standard] = $this->standardWeek();
        Holiday::factory()->working()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'name' => 'Working '.fake()->unique()->lexify('????'),
        ]);
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'type' => HolidayType::Regular,
        ]);

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-09'));

        $this->assertSame($standard->id, $day->shift->id);
        $this->assertSame([], $day->sides);
        $this->assertSame(WorkdayStatus::Holiday, $day->status);
        $this->assertSame(Premium::Regular, $day->premium);
    }

    public function test_a_working_holiday_keeps_the_shift(): void
    {
        ['employee' => $employee, 'standard' => $standard] = $this->standardWeek();
        Holiday::factory()->working()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
        ]);

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-09'));

        $this->assertSame($standard->id, $day->shift->id);
        $this->assertAt($day->sides, 0, '2026-09-09 08:00:00');
        $this->assertAt($day->sides, 3, '2026-09-09 17:00:00');
        $this->assertNull($day->status);
        $this->assertNull($day->premium);
    }

    /**
     * Rule d: whole-day empties; a windowed suspension truncates at starts
     * built from the date plus the raw clock string.
     */
    public function test_a_whole_day_suspension_empties_sides_and_is_not_premium(): void
    {
        $employee = $this->deployed();
        ['standard' => $standard] = $this->standardWeek($employee);
        Suspension::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
        ]);

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-09'));

        $this->assertSame($standard->id, $day->shift->id);
        $this->assertSame([], $day->sides);
        $this->assertSame(WorkdayStatus::Suspended, $day->status);
        $this->assertNull($day->premium);
    }

    public function test_a_windowed_suspension_truncates_at_starts(): void
    {
        $employee = $this->deployed();
        $this->standardWeek($employee);
        Suspension::factory()->partial('11:00:00', '17:00:00')->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
        ]);

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-09'));

        $this->assertCount(2, $day->sides);
        $this->assertAt($day->sides, 0, '2026-09-09 08:00:00');
        $this->assertAt($day->sides, 1, '2026-09-09 11:00:00');
        $this->assertNull($day->status);
        $this->assertNull($day->premium);
    }

    /** Rule e: an Off turn is rest. */
    public function test_an_off_turn_is_premium_rest(): void
    {
        ['employee' => $employee, 'off' => $off] = $this->standardWeek();

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-12'));

        $this->assertSame($off->id, $day->shift->id);
        $this->assertSame([], $day->sides);
        $this->assertSame(WorkdayStatus::Off, $day->status);
        $this->assertSame(Premium::Rest, $day->premium);
    }

    /**
     * Decision 49: local is special throughout, and expectsWork() is the
     * one place that decides — never a branch on the type here.
     */
    public function test_a_local_holiday_classifies_as_special(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'type' => HolidayType::Local,
        ]);

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-09'));

        $this->assertSame([], $day->sides);
        $this->assertSame(WorkdayStatus::Holiday, $day->status);
        $this->assertSame(Premium::Special, $day->premium);
    }

    /** Rule 10: coincident causes take the stronger: regular > special > rest. */
    public function test_coincident_causes_take_the_stronger_premium(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-12',
            'type' => HolidayType::Special,
            'name' => 'Special '.fake()->unique()->lexify('????'),
        ]);
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-12',
            'type' => HolidayType::Regular,
            'name' => 'Regular '.fake()->unique()->lexify('????'),
        ]);

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-12'));

        $this->assertSame(WorkdayStatus::Off, $day->status);
        $this->assertSame(Premium::Regular, $day->premium);
    }

    /**
     * The pair that proves status and premium are separate: a regular
     * holiday on a rest day is off (weaker expectation) and regular
     * (stronger cause). One derived from the other cannot satisfy both.
     */
    public function test_a_regular_holiday_on_a_rest_day_is_status_off_and_premium_regular(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-12',
            'type' => HolidayType::Regular,
        ]);

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-12'));

        $this->assertSame(WorkdayStatus::Off, $day->status);
        $this->assertSame(Premium::Regular, $day->premium);
    }

    /**
     * Decision 51: premium is classified even when premium_hours is false.
     * That setting gates credited in the deriver, not the class here.
     */
    public function test_premium_is_classified_when_premium_hours_is_false(): void
    {
        $this->agency->update(['settings' => ['premium_hours' => false]]);
        $this->agency->refresh();
        ['employee' => $employee] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'type' => HolidayType::Regular,
        ]);

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-09'));

        $this->assertSame(Premium::Regular, $day->premium);
    }

    /**
     * Rule f / decision 50: every covering exemption contributes a window
     * when excused(); the stamp is one, and a whole-day leave beats a
     * two-hour pass. Travel zeroes excess (the deriver reads the flag).
     */
    public function test_exemptions_collect_every_window_and_stamp_one(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        $date = CarbonImmutable::parse('2026-09-09');
        $pass = Exemption::factory()->hours('10:00:00', '12:00:00')->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => $date,
            'type' => ExemptionType::Pass,
            'approved_at' => $date->subDays(2),
        ]);
        $leave = Exemption::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => $date,
            'type' => ExemptionType::Leave,
            'approved_at' => $date->subDay(),
        ]);
        Exemption::factory()->personal()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => $date,
            'approved_at' => $date->subDays(3),
        ]);

        $day = $this->apply($employee, $date);

        $windows = collect($day->excused)->map(
            fn (array $window): string => $window[0]->format('Y-m-d H:i:s').'/'.$window[1]->format('Y-m-d H:i:s'),
        )->sort()->values()->all();

        $this->assertSame([
            '2026-09-09 00:00:00/2026-09-10 00:00:00',
            '2026-09-09 10:00:00/2026-09-09 12:00:00',
        ], $windows);
        $this->assertFalse($day->travel);
        $this->assertSame($leave->id, $day->exemptionId);
        $this->assertNotSame($pass->id, $day->exemptionId);
        $this->assertSame(WorkdayStatus::Exempt, $day->status);
    }

    /**
     * A personal slip excuses nothing but is still a candidate for the
     * stamp (decision 19, 50). A day with only that slip still stamps it.
     */
    public function test_a_personal_slip_stamps_but_does_not_excuse(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        $date = CarbonImmutable::parse('2026-09-09');
        $slip = Exemption::factory()->personal()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => $date,
        ]);

        $day = $this->apply($employee, $date);

        $this->assertSame([], $day->excused);
        $this->assertSame($slip->id, $day->exemptionId);
        $this->assertNull($day->status);
        $this->assertFalse($day->travel);
    }

    public function test_travel_marks_the_day(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        $date = CarbonImmutable::parse('2026-09-09');
        $travel = Exemption::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => $date,
            'type' => ExemptionType::Travel,
        ]);

        $day = $this->apply($employee, $date);

        $this->assertTrue($day->travel);
        $this->assertSame($travel->id, $day->exemptionId);
        $this->assertSame(WorkdayStatus::Exempt, $day->status);
    }

    /** Rule g: remote is told from Off by the flag, never the name. */
    public function test_a_remote_shift_is_status_remote(): void
    {
        $employee = $this->employee();
        $remote = Shift::factory()->remote()->create(['agency_id' => $this->agency->id]);
        $off = Shift::factory()->off()->create(['agency_id' => $this->agency->id]);
        $schedule = Schedule::factory()->withTurns($remote, $off)->create(['agency_id' => $this->agency->id]);
        Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => null,
        ]);

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-09'));

        $this->assertSame($remote->id, $day->shift->id);
        $this->assertSame([], $day->sides);
        $this->assertSame(WorkdayStatus::Remote, $day->status);
        $this->assertNull($day->premium);
    }

    /**
     * An ordinary working day: status null means the punches decide.
     * Calendar itself issues no queries — Almanac already loaded.
     */
    public function test_an_ordinary_working_day_leaves_status_null(): void
    {
        ['employee' => $employee, 'standard' => $standard] = $this->standardWeek();
        $date = CarbonImmutable::parse('2026-09-09');
        [$monday, $sunday] = Week::bounds($date);
        $resolutions = $this->resolutions($employee, $date);
        $calendar = new Calendar(Almanac::for($employee, $monday, $sunday), new Settings($this->agency));

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $day = $calendar->apply($resolutions, $date);

        $this->assertSame(0, $queries);
        $this->assertSame($standard->id, $day->shift->id);
        $this->assertAt($day->sides, 0, '2026-09-09 08:00:00');
        $this->assertAt($day->sides, 3, '2026-09-09 17:00:00');
        $this->assertNull($day->status);
        $this->assertNull($day->premium);
        $this->assertSame([], $day->excused);
        $this->assertFalse($day->travel);
        $this->assertNull($day->exemptionId);
        $this->assertSame('18:00', $day->nightFrom);
        $this->assertSame('2026-09-09', $day->date->toDateString());
    }

    public function test_night_from_is_carried_from_settings(): void
    {
        $this->agency->update(['settings' => ['night_from' => '22:00']]);
        $this->agency->refresh();
        ['employee' => $employee] = $this->standardWeek();

        $day = $this->apply($employee, CarbonImmutable::parse('2026-09-09'));

        $this->assertSame('22:00', $day->nightFrom);
    }
}

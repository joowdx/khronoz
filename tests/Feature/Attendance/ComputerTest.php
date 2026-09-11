<?php

namespace Tests\Feature\Attendance;

use App\Attendance\Computer;
use App\Enums\HolidayType;
use App\Enums\MissingSide;
use App\Enums\Premium;
use App\Enums\PunchKind;
use App\Enums\WorkdayStatus;
use App\Models\Agency;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Exemption;
use App\Models\Holiday;
use App\Models\Ledger;
use App\Models\Punch;
use App\Models\Roster;
use App\Models\Schedule;
use App\Models\Shift;
use App\Models\Suspension;
use App\Models\Timelog;
use App\Models\Turn;
use App\Models\User;
use App\Models\Workday;
use App\Models\Workgroup;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Orchestrate the attendance pipeline for one employee over a date
 * range and persist the workdays, punches and ledgers
 * (06-attendance.md Workday rules 1–3, across midnight, Ledger 1 and 3).
 *
 * The three worked examples in that document are the acceptance
 * criteria. Their numbers are hand-derived; a disagreement is a stop,
 * not a number to edit.
 */
class ComputerTest extends TestCase
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
     * Standard week, anchored Monday 7 September 2026.
     *
     * @return array{employee: Employee, enrollment: Enrollment, standard: Shift, off: Shift}
     */
    private function standardWeek(?Employee $employee = null): array
    {
        $employee ??= $this->employee();
        $standard = Shift::factory()->create(['agency_id' => $this->agency->id]);
        $off = Shift::factory()->off()->create(['agency_id' => $this->agency->id]);
        $schedule = Schedule::factory()->withTurns($standard, $off)->create(['agency_id' => $this->agency->id]);
        Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => null,
        ]);
        $enrollment = Enrollment::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
        ]);

        return compact('employee', 'enrollment', 'standard', 'off');
    }

    /**
     * Night then Standard, so 1 October would steal 06:00 if 30 September
     * had not claimed it first.
     *
     * @return array{employee: Employee, enrollment: Enrollment, night: Shift, standard: Shift}
     */
    private function nightThenStandard(): array
    {
        $employee = $this->employee();
        $night = Shift::factory()->create([
            'agency_id' => $this->agency->id,
            'name' => 'Hospital Night',
            'slots' => [
                ['in' => '22:00', 'out' => '30:00', 'window' => [-120, 120]],
            ],
            'required' => 480,
        ]);
        $standard = Shift::factory()->create(['agency_id' => $this->agency->id]);
        $schedule = Schedule::factory()->create([
            'agency_id' => $this->agency->id,
            'length' => 2,
        ]);
        Turn::factory()->create([
            'agency_id' => $this->agency->id,
            'schedule_id' => $schedule->id,
            'shift_id' => $night->id,
            'position' => 0,
        ]);
        Turn::factory()->create([
            'agency_id' => $this->agency->id,
            'schedule_id' => $schedule->id,
            'shift_id' => $standard->id,
            'position' => 1,
        ]);
        Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-30',
            'starts' => '2026-09-30',
            'ends' => null,
        ]);
        $enrollment = Enrollment::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
        ]);

        return compact('employee', 'enrollment', 'night', 'standard');
    }

    /**
     * Duty48, Off, Off, Off — the 48-hour duty across a month end.
     *
     * @return array{employee: Employee, enrollment: Enrollment, duty: Shift, off: Shift}
     */
    private function dutyFortyEight(): array
    {
        $employee = $this->employee();
        $duty = Shift::factory()->create([
            'agency_id' => $this->agency->id,
            'name' => 'Duty48',
            'slots' => [
                ['in' => '08:00', 'out' => '56:00', 'window' => [-60, 60]],
            ],
            'required' => 2880,
        ]);
        $off = Shift::factory()->off()->create(['agency_id' => $this->agency->id]);
        $schedule = Schedule::factory()->create([
            'agency_id' => $this->agency->id,
            'length' => 4,
        ]);

        foreach ([$duty->id, $off->id, $off->id, $off->id] as $position => $shiftId) {
            Turn::factory()->create([
                'agency_id' => $this->agency->id,
                'schedule_id' => $schedule->id,
                'shift_id' => $shiftId,
                'position' => $position,
            ]);
        }

        Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-30',
            'starts' => '2026-09-30',
            'ends' => null,
        ]);
        $enrollment = Enrollment::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
        ]);

        return compact('employee', 'enrollment', 'duty', 'off');
    }

    private function tap(Enrollment $enrollment, string $time, int $state = 0): Timelog
    {
        return Timelog::factory()->resolving($enrollment)->create([
            'time' => $time,
            'state' => $state,
        ]);
    }

    private function compute(Employee $employee, string $from, string $to): void
    {
        (new Computer($employee, new Settings($this->agency->fresh())))->over(
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        );
    }

    private function workday(Employee $employee, string $date): Workday
    {
        $workday = Workday::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();

        $this->assertNotNull($workday);

        return $workday->refresh();
    }

    /**
     * 06-attendance.md, "The chain, one day". Five timelogs, void missing
     * side: morning only is presence, excess 5, afternoon out is not.
     */
    public function test_the_ordinary_day_stores_the_chain_under_void(): void
    {
        ['employee' => $employee, 'enrollment' => $enrollment, 'standard' => $standard] = $this->standardWeek();
        $first = $this->tap($enrollment, '2026-09-08 07:58:00', 0);
        $duplicate = $this->tap($enrollment, '2026-09-08 07:58:00', 1);
        $noon = $this->tap($enrollment, '2026-09-08 12:03:00');
        $afternoon = $this->tap($enrollment, '2026-09-08 17:05:00');
        $stray = $this->tap($enrollment, '2026-09-08 19:31:00');

        $this->compute($employee, '2026-09-08', '2026-09-08');

        $workday = $this->workday($employee, '2026-09-08');
        $this->assertSame('2026-09-01', $workday->month->toDateString());
        $this->assertSame('2026-09-01', $workday->ledger->month->toDateString());
        $this->assertSame(WorkdayStatus::Present, $workday->status);
        $this->assertSame(null, $workday->premium);
        $this->assertSame(240, $workday->worked);
        $this->assertSame(0, $workday->credited);
        $this->assertSame(0, $workday->tardy);
        $this->assertSame(0, $workday->undertime);
        $this->assertSame(5, $workday->excess);
        $this->assertSame(0, $workday->night);
        $this->assertSame(0, $workday->night_excess);
        $this->assertSame($standard->id, $workday->shift_id);
        $this->assertNotNull($workday->computed_at);

        $punches = $workday->punches()->orderBy('slot')->orderBy('kind')->get();
        $this->assertCount(4, $punches);
        $this->assertPunch($punches[0], 1, PunchKind::In, '2026-09-08 08:00:00', $first->id, '2026-09-08 07:58:00', -2);
        $this->assertPunch($punches[1], 1, PunchKind::Out, '2026-09-08 12:00:00', $noon->id, '2026-09-08 12:03:00', 3);
        $this->assertPunch($punches[2], 2, PunchKind::In, '2026-09-08 13:00:00', null, null, null);
        $this->assertPunch($punches[3], 2, PunchKind::Out, '2026-09-08 17:00:00', $afternoon->id, '2026-09-08 17:05:00', 5);

        $claimed = Punch::query()->whereNotNull('timelog_id')->pluck('timelog_id');
        $this->assertTrue($claimed->contains($first->id));
        $this->assertFalse($claimed->contains($duplicate->id));
        $this->assertFalse($claimed->contains($stray->id));

        $snapshot = $workday->shift;
        $this->assertSame($standard->id, $snapshot['shift']['id']);
        $this->assertSame($standard->name, $snapshot['shift']['name']);
        $this->assertSame($standard->slots, $snapshot['shift']['slots']);
        $this->assertSame(480, $snapshot['shift']['required']);
        $this->assertSame(0, $snapshot['shift']['flex']);
        $this->assertSame(false, $snapshot['shift']['remote']);
        $this->assertSame(false, $snapshot['shift']['trust']);
        $this->assertSame('18:00', $snapshot['settings']['night_from']);
        $this->assertSame(false, $snapshot['settings']['premium_hours']);
        $this->assertSame(true, $snapshot['settings']['suspension_charge']);
        $this->assertSame(MissingSide::Void->value, $snapshot['settings']['missing_side']);
        $this->assertArrayNotHasKey('occurrences', $snapshot['settings']);
        $this->assertArrayNotHasKey('overtime_after_weekly', $snapshot['settings']);
        $this->assertSame([], $snapshot['holidays']);
        $this->assertSame([], $snapshot['suspensions']);
    }

    /**
     * Same taps under assume: the half-filled afternoon is credited, the
     * five leftover minutes become excess, tardy and undertime stay 0.
     */
    public function test_the_ordinary_day_under_assume_credits_the_half_filled_slot(): void
    {
        $this->agency->update(['settings' => ['missing_side' => MissingSide::Assume->value]]);
        ['employee' => $employee, 'enrollment' => $enrollment] = $this->standardWeek();
        $this->tap($enrollment, '2026-09-08 07:58:00', 0);
        $this->tap($enrollment, '2026-09-08 07:58:00', 1);
        $this->tap($enrollment, '2026-09-08 12:03:00');
        $this->tap($enrollment, '2026-09-08 17:05:00');
        $this->tap($enrollment, '2026-09-08 19:31:00');

        $this->compute($employee, '2026-09-08', '2026-09-08');

        $workday = $this->workday($employee, '2026-09-08');
        $this->assertSame(WorkdayStatus::Present, $workday->status);
        $this->assertSame(480, $workday->worked);
        $this->assertSame(0, $workday->credited);
        $this->assertSame(0, $workday->tardy);
        $this->assertSame(0, $workday->undertime);
        $this->assertSame(10, $workday->excess);
        $this->assertSame(MissingSide::Assume->value, $workday->shift['settings']['missing_side']);
    }

    /**
     * Night 22:00–30:00 on 30 September; the 06:00 out is 1 October and
     * still belongs to September. 1 October has its own shift and must
     * not claim that tap.
     */
    public function test_the_night_shift_out_on_1_october_belongs_to_the_september_ledger(): void
    {
        ['employee' => $employee, 'enrollment' => $enrollment, 'night' => $night, 'standard' => $standard] = $this->nightThenStandard();
        $in = $this->tap($enrollment, '2026-09-30 22:00:00');
        $out = $this->tap($enrollment, '2026-10-01 06:00:00');

        $this->compute($employee, '2026-09-30', '2026-10-01');

        $september = $this->workday($employee, '2026-09-30');
        $this->assertSame('2026-09-01', $september->month->toDateString());
        $this->assertSame('2026-09-01', $september->ledger->month->toDateString());
        $this->assertSame($night->id, $september->shift_id);
        $this->assertSame(WorkdayStatus::Present, $september->status);
        $this->assertSame(480, $september->worked);
        $this->assertSame(0, $september->excess);
        $this->assertSame(0, $september->tardy);
        $this->assertSame(0, $september->undertime);
        $this->assertSame(480, $september->night);
        $this->assertSame(0, $september->night_excess);

        $punches = $september->punches()->orderBy('slot')->orderBy('kind')->get();
        $this->assertCount(2, $punches);
        $this->assertPunch($punches[0], 1, PunchKind::In, '2026-09-30 22:00:00', $in->id, '2026-09-30 22:00:00', 0);
        $this->assertPunch($punches[1], 1, PunchKind::Out, '2026-10-01 06:00:00', $out->id, '2026-10-01 06:00:00', 0);

        $october = $this->workday($employee, '2026-10-01');
        $this->assertSame('2026-10-01', $october->month->toDateString());
        $this->assertSame($standard->id, $october->shift_id);
        $this->assertFalse($october->punches()->where('timelog_id', $out->id)->exists());
        $this->assertSame($september->id, Punch::query()->where('timelog_id', $out->id)->value('workday_id'));
    }

    /**
     * Duty48 08:00–56:00 starting 30 September. All 2880 minutes, and
     * both nightly windows (1440), belong to September — never split.
     */
    public function test_the_forty_eight_hour_duty_credits_september_with_the_october_hours(): void
    {
        ['employee' => $employee, 'enrollment' => $enrollment, 'duty' => $duty] = $this->dutyFortyEight();
        $in = $this->tap($enrollment, '2026-09-30 08:00:00');
        $out = $this->tap($enrollment, '2026-10-02 08:00:00');

        $this->compute($employee, '2026-09-30', '2026-10-02');

        $workday = $this->workday($employee, '2026-09-30');
        $this->assertSame('2026-09-01', $workday->month->toDateString());
        $this->assertSame($duty->id, $workday->shift_id);
        $this->assertSame(WorkdayStatus::Present, $workday->status);
        $this->assertSame(2880, $workday->worked);
        $this->assertSame(0, $workday->excess);
        $this->assertSame(0, $workday->tardy);
        $this->assertSame(0, $workday->undertime);
        $this->assertSame(1440, $workday->night);
        $this->assertSame(0, $workday->night_excess);

        $punches = $workday->punches()->orderBy('slot')->orderBy('kind')->get();
        $this->assertCount(2, $punches);
        $this->assertPunch($punches[0], 1, PunchKind::In, '2026-09-30 08:00:00', $in->id, '2026-09-30 08:00:00', 0);
        $this->assertPunch($punches[1], 1, PunchKind::Out, '2026-10-02 08:00:00', $out->id, '2026-10-02 08:00:00', 0);

        $second = $this->workday($employee, '2026-10-02');
        $this->assertFalse($second->punches()->where('timelog_id', $out->id)->exists());
        $this->assertSame($workday->id, Punch::query()->where('timelog_id', $out->id)->value('workday_id'));
    }

    /** Decision 70: a locked month is skipped, not written and not thrown on. */
    public function test_a_locked_ledger_skips_the_date(): void
    {
        ['employee' => $employee, 'enrollment' => $enrollment] = $this->standardWeek();
        $this->tap($enrollment, '2026-09-08 08:00:00');
        Ledger::factory()->locked()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'month' => '2026-09-01',
        ]);

        $this->compute($employee, '2026-09-08', '2026-09-08');

        $this->assertFalse(Workday::query()->where('employee_id', $employee->id)->exists());
        $this->assertFalse(Punch::query()->where('employee_id', $employee->id)->exists());
    }

    /** A recompute replaces the day's punches wholesale rather than stacking them. */
    public function test_a_recompute_replaces_the_days_punches(): void
    {
        ['employee' => $employee, 'enrollment' => $enrollment] = $this->standardWeek();
        $this->tap($enrollment, '2026-09-08 07:58:00', 0);
        $this->tap($enrollment, '2026-09-08 07:58:00', 1);
        $this->tap($enrollment, '2026-09-08 12:03:00');
        $this->tap($enrollment, '2026-09-08 17:05:00');
        $this->tap($enrollment, '2026-09-08 19:31:00');

        $this->compute($employee, '2026-09-08', '2026-09-08');
        $this->compute($employee, '2026-09-08', '2026-09-08');

        $workday = $this->workday($employee, '2026-09-08');
        $this->assertCount(4, $workday->punches);
        $this->assertSame(240, $workday->worked);
        $this->assertSame(1, Workday::query()->where('employee_id', $employee->id)->count());
    }

    /** Decision 57: a voided tap is not a candidate, so the day does not count it. */
    public function test_a_voided_timelog_is_not_claimed(): void
    {
        ['employee' => $employee, 'enrollment' => $enrollment] = $this->standardWeek();
        $this->tap($enrollment, '2026-09-08 07:58:00');
        $this->tap($enrollment, '2026-09-08 12:00:00', 1);
        $this->tap($enrollment, '2026-09-08 13:00:00');
        $this->tap($enrollment, '2026-09-08 17:00:00', 1);
        $voided = $this->tap($enrollment, '2026-09-08 08:00:00');
        $actor = User::factory()->create(['agency_id' => $this->agency->id]);
        $voided->void('Duplicate scan', $actor);

        $this->compute($employee, '2026-09-08', '2026-09-08');

        $this->assertFalse(Punch::query()->where('timelog_id', $voided->id)->exists());
        $this->assertSame(480, $this->workday($employee, '2026-09-08')->worked);
    }

    /**
     * Decision 69: holidays of the date are frozen into the snapshot.
     * Saturday is Off, so the figures stay zero; the copy is the point.
     */
    public function test_the_snapshot_copies_holidays_of_the_date(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        $holiday = Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-12',
            'name' => 'Charter Day',
            'type' => HolidayType::Regular,
        ]);

        $this->compute($employee, '2026-09-12', '2026-09-12');

        $snapshot = $this->workday($employee, '2026-09-12')->shift;
        $this->assertCount(1, $snapshot['holidays']);
        $this->assertSame($holiday->id, $snapshot['holidays'][0]['id']);
        $this->assertSame('Charter Day', $snapshot['holidays'][0]['name']);
        $this->assertSame(HolidayType::Regular->value, $snapshot['holidays'][0]['type']);
        $this->assertArrayNotHasKey('reference', $snapshot['holidays'][0]);
    }

    /**
     * Decisions 66 and 68: a regular holiday looks back past rest days
     * to the preceding work day. An unexcused absence there withholds
     * the credit; the in-run row is the authority.
     */
    public function test_a_regular_holiday_withholds_credit_after_an_unexcused_absence(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'type' => HolidayType::Regular,
        ]);

        $this->compute($employee, '2026-09-08', '2026-09-09');

        $this->assertSame(WorkdayStatus::Absent, $this->workday($employee, '2026-09-08')->status);
        $holiday = $this->workday($employee, '2026-09-09');
        $this->assertSame(WorkdayStatus::Holiday, $holiday->status);
        $this->assertSame(0, $holiday->worked);
    }

    public function test_a_regular_holiday_credits_required_when_there_is_no_preceding_work_day(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-08',
            'type' => HolidayType::Regular,
        ]);

        $this->compute($employee, '2026-09-08', '2026-09-08');

        $workday = $this->workday($employee, '2026-09-08');
        $this->assertSame(WorkdayStatus::Holiday, $workday->status);
        $this->assertSame(480, $workday->worked);
    }

    /**
     * The load window is `to + 4 days`, not `to`. Computing only the
     * duty's start date must still see the out two days later.
     */
    public function test_a_single_date_compute_still_claims_the_duty_out_two_days_later(): void
    {
        ['employee' => $employee, 'enrollment' => $enrollment] = $this->dutyFortyEight();
        $out = $this->tap($enrollment, '2026-10-02 08:00:00');
        $this->tap($enrollment, '2026-09-30 08:00:00');

        $this->compute($employee, '2026-09-30', '2026-09-30');

        $workday = $this->workday($employee, '2026-09-30');
        $this->assertSame(2880, $workday->worked);
        $this->assertTrue($workday->punches()->where('timelog_id', $out->id)->exists());
    }

    /**
     * The load window is `from − 1 day`, not `from`. A 00:30 in with
     * window −120 opens at 22:30 the evening before.
     */
    public function test_an_in_window_that_opens_the_evening_before_still_claims_that_tap(): void
    {
        $employee = $this->employee();
        $early = Shift::factory()->create([
            'agency_id' => $this->agency->id,
            'slots' => [
                ['in' => '00:30', 'out' => '08:30', 'window' => [-120, 120]],
            ],
            'required' => 480,
        ]);
        $off = Shift::factory()->off()->create(['agency_id' => $this->agency->id]);
        $schedule = Schedule::factory()->withTurns($early, $off)->create(['agency_id' => $this->agency->id]);
        Roster::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'schedule_id' => $schedule->id,
            'anchor' => '2026-09-07',
            'starts' => '2026-09-07',
            'ends' => null,
        ]);
        $enrollment = Enrollment::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
        ]);
        $in = $this->tap($enrollment, '2026-09-08 23:50:00');
        $this->tap($enrollment, '2026-09-09 08:30:00', 1);

        $this->compute($employee, '2026-09-09', '2026-09-09');

        $punch = $this->workday($employee, '2026-09-09')->punches()
            ->where('slot', 1)
            ->where('kind', PunchKind::In)
            ->first();
        $this->assertNotNull($punch);
        $this->assertSame($in->id, $punch->timelog_id);
        $this->assertSame('2026-09-08 23:50:00', $punch->actual_at->format('Y-m-d H:i:s'));
    }

    /**
     * The immediately preceding **work** day: an Off turn between the
     * absence and the holiday is skipped, not a stop, so it does not
     * launder the absence.
     */
    public function test_a_rest_day_between_the_absence_and_the_holiday_does_not_launder_the_absence(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-14',
            'type' => HolidayType::Regular,
        ]);

        $this->compute($employee, '2026-09-11', '2026-09-14');

        $this->assertSame(WorkdayStatus::Absent, $this->workday($employee, '2026-09-11')->status);
        $this->assertSame(WorkdayStatus::Off, $this->workday($employee, '2026-09-12')->status);
        $holiday = $this->workday($employee, '2026-09-14');
        $this->assertSame(WorkdayStatus::Holiday, $holiday->status);
        $this->assertSame(0, $holiday->worked);
    }

    /**
     * Decision 78, end to end. Nine hours of duty on a rest day, and before
     * this the whole day recorded `worked 0 credited 0 excess 0` with no
     * punch rows: `Matcher::match()` returned nothing when the expectation
     * was empty, so daily rule 10's "first 480 minutes of actual attendance"
     * had no attendance to read. Status stays `off` — rule 1, the status
     * describes the expectation — and the work shows in `credited`,
     * `excess` and `premium`.
     */
    public function test_a_rest_day_worked_records_its_minutes_and_its_punches(): void
    {
        $this->agency->update(['settings' => ['premium_hours' => true]]);
        ['employee' => $employee, 'enrollment' => $enrollment] = $this->standardWeek();

        // 12 September 2026 is a Saturday and an Off turn.
        $this->tap($enrollment, '2026-09-12 08:00:00');
        $this->tap($enrollment, '2026-09-12 12:00:00', 1);
        $this->tap($enrollment, '2026-09-12 13:00:00');
        $this->tap($enrollment, '2026-09-12 18:00:00', 1);

        $this->compute($employee, '2026-09-12', '2026-09-12');

        $workday = $this->workday($employee, '2026-09-12');
        $this->assertSame(WorkdayStatus::Off, $workday->status);
        $this->assertSame(Premium::Rest, $workday->premium);
        $this->assertSame(0, $workday->worked);
        $this->assertSame(480, $workday->credited);
        $this->assertSame(60, $workday->excess);
        $this->assertSame(0, $workday->tardy);
        $this->assertSame(0, $workday->undertime);

        $punches = $workday->punches()->orderBy('slot')->orderBy('kind')->get();
        $this->assertCount(4, $punches);

        foreach ($punches as $punch) {
            $this->assertNull($punch->expected_at, 'a transit answers no expectation');
            $this->assertNull($punch->deviation);
            $this->assertNotNull($punch->timelog_id);
        }

        $this->assertSame(
            ['08:00', '12:00', '13:00', '18:00'],
            $punches->map(fn (Punch $punch): string => $punch->actual_at->format('H:i'))->sort()->values()->all(),
        );
    }

    /**
     * The same for a non-working holiday, which the calendar empties by a
     * different road (05-calendar.md rule 1) and which arrives at the same
     * matcher. `credited` stays 0 under CSC, where `premium_hours` is false
     * and holiday work is `excess` against an authority — the minutes are
     * recorded either way, which is the point.
     */
    public function test_a_non_working_holiday_worked_records_its_minutes(): void
    {
        ['employee' => $employee, 'enrollment' => $enrollment] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'type' => HolidayType::Special,
        ]);

        $this->tap($enrollment, '2026-09-09 08:00:00');
        $this->tap($enrollment, '2026-09-09 17:00:00', 1);

        $this->compute($employee, '2026-09-09', '2026-09-09');

        $workday = $this->workday($employee, '2026-09-09');
        $this->assertSame(WorkdayStatus::Holiday, $workday->status);
        $this->assertSame(Premium::Special, $workday->premium);
        $this->assertSame(0, $workday->credited);
        $this->assertSame(540, $workday->excess);
        $this->assertSame(2, $workday->punches()->count());
    }

    /**
     * Decision 77: a holiday between the absence and the holiday does not
     * launder it either. A special non-working holiday expects no work, so
     * it is not "the immediately preceding work day" and the walk goes past
     * it — the Christmas case, where an employee absent without leave on
     * the 23rd was paid the 25th because Christmas Eve sat in between.
     */
    public function test_a_holiday_between_the_absence_and_the_holiday_does_not_launder_the_absence(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'type' => HolidayType::Special,
        ]);
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-10',
            'type' => HolidayType::Regular,
        ]);

        $this->compute($employee, '2026-09-08', '2026-09-10');

        $this->assertSame(WorkdayStatus::Absent, $this->workday($employee, '2026-09-08')->status);
        $this->assertSame(WorkdayStatus::Holiday, $this->workday($employee, '2026-09-09')->status);
        $holiday = $this->workday($employee, '2026-09-10');
        $this->assertSame(WorkdayStatus::Holiday, $holiday->status);
        $this->assertSame(0, $holiday->worked);
    }

    /**
     * The other side of decision 77's `Exempt => true`: an excusing
     * exemption **is** the answer when it is the preceding work day's own
     * status. Absent without leave Monday, on approved leave Tuesday,
     * regular holiday Wednesday — section F asks about Tuesday and
     * Tuesday alone, so the credit stands. The walk stops at `exempt`; it
     * only walks past days nothing was required on.
     */
    public function test_an_excused_day_stops_the_walk_short_of_an_earlier_absence(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'type' => HolidayType::Regular,
        ]);
        Exemption::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-08',
        ]);

        $this->compute($employee, '2026-09-07', '2026-09-09');

        $this->assertSame(WorkdayStatus::Absent, $this->workday($employee, '2026-09-07')->status);
        $this->assertSame(WorkdayStatus::Exempt, $this->workday($employee, '2026-09-08')->status);
        $this->assertSame(480, $this->workday($employee, '2026-09-09')->worked);
    }

    /**
     * The third day nothing was required on. A whole-day work suspension is
     * not the preceding work day either — the employee could not have been
     * present on it — so it does not launder the absence before it.
     */
    public function test_a_suspended_day_between_the_absence_and_the_holiday_does_not_launder_it(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        Deployment::factory()->create([
            'agency_id' => $this->agency->id,
            'workgroup_id' => Workgroup::factory()->create(['agency_id' => $this->agency->id])->id,
            'employee_id' => $employee->id,
            'starts' => '2026-01-01',
            'ends' => '2026-12-31',
        ]);
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-10',
            'type' => HolidayType::Regular,
        ]);
        Suspension::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
        ]);

        $this->compute($employee, '2026-09-08', '2026-09-10');

        $this->assertSame(WorkdayStatus::Absent, $this->workday($employee, '2026-09-08')->status);
        $this->assertSame(WorkdayStatus::Suspended, $this->workday($employee, '2026-09-09')->status);
        $this->assertSame(0, $this->workday($employee, '2026-09-10')->worked);
    }

    /**
     * Decision 77: an excusing exemption covering **part** of a day of no
     * attendance leaves the day `absent`, and an absence it does not cover
     * is not excused. `Calendar::status()` reaches `exempt` only for a
     * whole-day excuse, so this is the only shape an excusing stamp can
     * take on an `absent` day — and reading that stamp paid the holiday
     * off two excused hours.
     */
    public function test_a_partial_excusing_exemption_does_not_excuse_a_day_of_no_attendance(): void
    {
        ['employee' => $employee] = $this->standardWeek();
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'type' => HolidayType::Regular,
        ]);
        Exemption::factory()->hours()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $employee->id,
            'date' => '2026-09-08',
        ]);

        $this->compute($employee, '2026-09-08', '2026-09-09');

        $absent = $this->workday($employee, '2026-09-08');
        $this->assertSame(WorkdayStatus::Absent, $absent->status);
        $this->assertNotNull($absent->exemption_id);
        $this->assertSame(0, $this->workday($employee, '2026-09-09')->worked);
    }

    /**
     * Decision 19 / 68: "on leave with pay" is an *excusing* exemption,
     * not a stamp. A whole-day leave still credits the holiday; a
     * `personal` slip on an otherwise absent day does not.
     */
    public function test_a_regular_holiday_credits_after_an_excused_absence_and_withholds_after_a_personal_slip(): void
    {
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'type' => HolidayType::Regular,
        ]);

        ['employee' => $onLeave] = $this->standardWeek();
        Exemption::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $onLeave->id,
            'date' => '2026-09-08',
        ]);
        $this->compute($onLeave, '2026-09-08', '2026-09-08');
        $this->compute($onLeave, '2026-09-09', '2026-09-09');
        $this->assertSame(WorkdayStatus::Exempt, $this->workday($onLeave, '2026-09-08')->status);
        $this->assertSame(480, $this->workday($onLeave, '2026-09-09')->worked);

        ['employee' => $personal] = $this->standardWeek();
        Exemption::factory()->personal()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $personal->id,
            'date' => '2026-09-08',
        ]);
        $this->compute($personal, '2026-09-08', '2026-09-08');
        $this->compute($personal, '2026-09-09', '2026-09-09');
        $this->assertSame(WorkdayStatus::Absent, $this->workday($personal, '2026-09-08')->status);
        $this->assertSame(0, $this->workday($personal, '2026-09-09')->worked);
    }

    /**
     * The same rule, reached by the other road. The test above computes each
     * date in its own call, so the preceding day is read back from
     * `workdays` — but `RecomputeWorkdays` walks four dates inside **one**
     * job, and there the preceding day is still only in `$computed`. The two
     * branches ask the same question of different data and either can be
     * wrong alone: mutating the in-run one to read `exemptionId !== null`
     * killed nothing until this test existed.
     */
    public function test_the_preceding_day_is_judged_the_same_way_within_one_run(): void
    {
        Holiday::factory()->create([
            'agency_id' => $this->agency->id,
            'date' => '2026-09-09',
            'type' => HolidayType::Regular,
        ]);

        ['employee' => $onLeave] = $this->standardWeek();
        Exemption::factory()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $onLeave->id,
            'date' => '2026-09-08',
        ]);
        $this->compute($onLeave, '2026-09-08', '2026-09-09');
        $this->assertSame(480, $this->workday($onLeave, '2026-09-09')->worked);

        ['employee' => $personal] = $this->standardWeek();
        Exemption::factory()->personal()->create([
            'agency_id' => $this->agency->id,
            'employee_id' => $personal->id,
            'date' => '2026-09-08',
        ]);
        $this->compute($personal, '2026-09-08', '2026-09-09');
        $this->assertSame(WorkdayStatus::Absent, $this->workday($personal, '2026-09-08')->status);
        $this->assertSame(0, $this->workday($personal, '2026-09-09')->worked);
    }

    private function assertPunch(
        Punch $punch,
        int $slot,
        PunchKind $kind,
        string $expected,
        ?string $timelogId,
        ?string $actual,
        ?int $deviation,
    ): void {
        $this->assertSame($slot, $punch->slot);
        $this->assertSame($kind, $punch->kind);
        $this->assertSame($expected, $punch->expected_at->format('Y-m-d H:i:s'));
        $this->assertSame($timelogId, $punch->timelog_id);
        $this->assertSame($deviation, $punch->deviation);

        if ($actual === null) {
            $this->assertSame(null, $punch->actual_at);

            return;
        }

        $this->assertSame($actual, $punch->actual_at->format('Y-m-d H:i:s'));
    }
}

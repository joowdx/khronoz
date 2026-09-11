<?php

namespace Tests\Feature\Models;

use App\Attendance\LedgerView;
use App\Enums\Period;
use App\Enums\Premium;
use App\Enums\Work;
use App\Enums\WorkdayStatus;
use App\Models\Agency;
use App\Models\Exemption;
use App\Models\Ledger;
use App\Models\Overtime;
use App\Models\Workday;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ledger::view() — the month read back (06-attendance.md Ledger rules 1–2,
 * daily rules 6, 9 and 11; 05-calendar.md rule 6; decisions 52 and 72).
 *
 * Everything here is derived at read time. A stored total would be a cache
 * of workdays that one recompute puts out of date.
 */
class LedgerViewTest extends TestCase
{
    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::factory()->create();
        $this->withTenant($this->agency);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function ledger(array $settings = []): Ledger
    {
        if ($settings !== []) {
            $this->agency->update(['settings' => $settings]);
            $this->agency->refresh();
        }

        return Ledger::factory()->create([
            'agency_id' => $this->agency->id,
            'month' => '2026-09-01',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function workday(Ledger $ledger, string $date, array $attributes = []): Workday
    {
        return Workday::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'ledger_id' => $ledger->id,
            'date' => $date,
            ...$attributes,
        ]);
    }

    private function authority(Ledger $ledger, string $date): Overtime
    {
        return Overtime::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'starts' => $date.' 17:00:00',
            'ends' => $date.' 21:00:00',
        ]);
    }

    /** @return list<string> */
    private function dates(LedgerView $view): array
    {
        return $view->workdays
            ->map(fn (Workday $workday): string => $workday->date->toDateString())
            ->values()
            ->all();
    }

    public function test_first_period_is_days_1_to_15(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01');
        $this->workday($ledger, '2026-09-15');
        $this->workday($ledger, '2026-09-16');

        $view = $ledger->view(Period::First);

        $this->assertSame(['2026-09-01', '2026-09-15'], $this->dates($view));
    }

    public function test_second_period_is_day_16_to_the_end_of_the_month(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-15');
        $this->workday($ledger, '2026-09-16');
        $this->workday($ledger, '2026-09-30');

        $view = $ledger->view(Period::Second);

        $this->assertSame(['2026-09-16', '2026-09-30'], $this->dates($view));
    }

    public function test_full_period_is_the_whole_month_in_date_order(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-30');
        $this->workday($ledger, '2026-09-01');
        $this->workday($ledger, '2026-09-16');

        $view = $ledger->view(Period::Full);

        $this->assertSame(['2026-09-01', '2026-09-16', '2026-09-30'], $this->dates($view));
    }

    /**
     * Across midnight rule 2: the ledger month is the workday's date, never
     * a punch's actual_at. A night shift of 30 September belongs to second
     * even though its out is in October.
     */
    public function test_a_period_slice_follows_the_workday_date(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-30', ['worked' => 480, 'night' => 360]);

        $this->assertSame(['2026-09-30'], $this->dates($ledger->view(Period::Second)));
        $this->assertSame([], $this->dates($ledger->view(Period::First)));
        $this->assertSame(480, $ledger->view(Period::Second)->worked);
        $this->assertSame(0, $ledger->view(Period::First)->worked);
    }

    public function test_seven_minute_totals_are_the_sum_of_the_period(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', [
            'premium' => Premium::Special,
            'worked' => 400,
            'credited' => 10,
            'tardy' => 20,
            'undertime' => 30,
            'excess' => 40,
            'night' => 50,
            'night_excess' => 60,
        ]);
        $this->workday($ledger, '2026-09-16', [
            'premium' => Premium::Rest,
            'worked' => 80,
            'credited' => 5,
            'tardy' => 6,
            'undertime' => 7,
            'excess' => 8,
            'night' => 9,
            'night_excess' => 10,
        ]);

        $first = $ledger->view(Period::First);
        $full = $ledger->view(Period::Full);

        $this->assertSame(400, $first->worked);
        $this->assertSame(10, $first->credited);
        $this->assertSame(20, $first->tardy);
        $this->assertSame(30, $first->undertime);
        $this->assertSame(40, $first->excess);
        $this->assertSame(50, $first->night);
        $this->assertSame(60, $first->nightExcess);

        $this->assertSame(480, $full->worked);
        $this->assertSame(15, $full->credited);
        $this->assertSame(26, $full->tardy);
        $this->assertSame(37, $full->undertime);
        $this->assertSame(48, $full->excess);
        $this->assertSame(59, $full->night);
        $this->assertSame(70, $full->nightExcess);
    }

    public function test_another_employee_s_workdays_are_not_included(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['worked' => 480]);
        $other = $this->ledger();
        $this->workday($other, '2026-09-01', ['worked' => 120]);

        $this->assertSame(480, $ledger->view(Period::Full)->worked);
        $this->assertSame(120, $other->view(Period::Full)->worked);
    }

    /**
     * A day with 40 tardy minutes is one occurrence, not forty. MC 04 s. 1991
     * and MC 16 s. 2010 count days, not minutes.
     */
    public function test_a_tardy_day_is_one_occurrence_regardless_of_minutes(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['tardy' => 40]);
        $this->workday($ledger, '2026-09-02', ['tardy' => 5]);
        $this->workday($ledger, '2026-09-03', ['tardy' => 0, 'worked' => 480]);

        $view = $ledger->view(Period::Full);

        $this->assertSame(2, $view->tardyOccurrences);
        $this->assertSame(45, $view->tardy);
    }

    public function test_a_day_with_tardy_and_undertime_counts_once_in_each(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['tardy' => 15, 'undertime' => 20]);

        $view = $ledger->view(Period::Full);

        $this->assertSame(1, $view->tardyOccurrences);
        $this->assertSame(1, $view->undertimeOccurrences);
    }

    public function test_absent_days_without_an_exemption_are_counted(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['status' => WorkdayStatus::Absent]);
        $this->workday($ledger, '2026-09-02', ['status' => WorkdayStatus::Present, 'worked' => 480]);

        $this->assertSame(1, $ledger->view(Period::Full)->absences);
    }

    /**
     * Decision 77: a whole-day excusing exemption never reaches the count,
     * because it never reaches `absent` — `Calendar::status()` makes that
     * day `exempt`. This is the fixture the occurrence rule actually has
     * to exclude, and the status excludes it on its own.
     */
    public function test_an_exempt_day_is_not_an_occurrence(): void
    {
        $ledger = $this->ledger();
        $leave = Exemption::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'date' => '2026-09-01',
        ]);
        $this->workday($ledger, '2026-09-01', [
            'status' => WorkdayStatus::Exempt,
            'exemption_id' => $leave->id,
        ]);

        $this->assertSame(0, $ledger->view(Period::Full)->absences);
    }

    /**
     * Decision 77: the stamp on an `absent` day is a **partial** excuse by
     * construction, and two excused hours do not excuse six unexcused ones.
     * Reading the stamp's `excused()` here dropped a day of no attendance
     * out of the habitual-absenteeism count entirely.
     */
    public function test_an_absent_day_with_a_partial_excusing_exemption_is_an_occurrence(): void
    {
        $ledger = $this->ledger();
        $pass = Exemption::factory()->hours()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'date' => '2026-09-01',
        ]);
        $this->assertTrue($pass->excused());
        $this->workday($ledger, '2026-09-01', [
            'status' => WorkdayStatus::Absent,
            'exemption_id' => $pass->id,
        ]);

        $this->assertSame(1, $ledger->view(Period::Full)->absences);
    }

    /**
     * Decision 73: a personal locator slip is stamped and excuses nothing.
     * Daily rule 7 says the day's absence stands as the punches make it, so
     * the stamp must not suppress a count that feeds habitual absenteeism
     * under MC 04 s. 1991. Decision 77 keeps the outcome and drops the
     * mechanism: the status is the whole test now.
     */
    public function test_an_absent_day_with_a_personal_exemption_is_an_occurrence(): void
    {
        $ledger = $this->ledger();
        $personal = Exemption::factory()->personal()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'date' => '2026-09-01',
        ]);
        $this->workday($ledger, '2026-09-01', [
            'status' => WorkdayStatus::Absent,
            'exemption_id' => $personal->id,
        ]);

        $this->assertSame(1, $ledger->view(Period::Full)->absences);
    }

    /**
     * Labor Code: no statutory occurrence counting exists, and a
     * habitual-tardiness count on a private employer's DTR is a number with
     * no rule behind it.
     */
    public function test_occurrence_counts_are_zero_when_settings_occurrences_is_false(): void
    {
        $ledger = $this->ledger(['occurrences' => false]);
        $this->workday($ledger, '2026-09-01', ['tardy' => 40, 'undertime' => 15]);
        $this->workday($ledger, '2026-09-02', ['status' => WorkdayStatus::Absent]);

        $view = $ledger->view(Period::Full);

        $this->assertSame(0, $view->tardyOccurrences);
        $this->assertSame(0, $view->undertimeOccurrences);
        $this->assertSame(0, $view->absences);
        $this->assertSame(40, $view->tardy);
        $this->assertSame(15, $view->undertime);
    }

    public function test_null_work_includes_compensable_overtime(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['worked' => 480, 'excess' => 180]);
        $this->authority($ledger, '2026-09-01');

        $view = $ledger->view(Period::Full);

        $this->assertSame(480, $view->worked);
        $this->assertSame(180, $view->overtime);
    }

    public function test_regular_work_reports_overtime_as_zero(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['worked' => 480, 'excess' => 180]);
        $this->authority($ledger, '2026-09-01');

        $view = $ledger->view(Period::Full, Work::Regular);

        $this->assertSame(480, $view->worked);
        $this->assertSame(180, $view->excess);
        $this->assertSame(0, $view->overtime);
    }

    public function test_overtime_work_includes_compensable_overtime(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['worked' => 480, 'excess' => 180]);
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(180, $ledger->view(Period::Full, Work::Overtime)->overtime);
    }

    public function test_excess_is_compensable_when_an_authority_covers_the_date(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['excess' => 180]);
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(180, $ledger->view(Period::Full)->overtime);
    }

    public function test_excess_is_not_compensable_without_an_authority(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['excess' => 180]);

        $this->assertSame(0, $ledger->view(Period::Full)->overtime);
        $this->assertSame(180, $ledger->view(Period::Full)->excess);
    }

    /**
     * JC 2 s. 2015 §10.1 is "arrive on or before the start of the workday".
     * The gate is tardy === 0, which with a non-zero grace is not quite that
     * text — and that is deliberate (see Ledger::view()).
     */
    public function test_excess_is_not_compensable_when_the_employee_was_tardy(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['tardy' => 1, 'excess' => 180]);
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(0, $ledger->view(Period::Full)->overtime);
        $this->assertSame(1, $ledger->view(Period::Full)->tardy);
    }

    public function test_excess_is_not_compensable_below_two_hours(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['excess' => 119]);
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(0, $ledger->view(Period::Full)->overtime);
    }

    public function test_exactly_two_hours_of_excess_is_compensable(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['excess' => 120]);
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(120, $ledger->view(Period::Full)->overtime);
    }

    /**
     * §10.5: at most 720 minutes of paid overtime on a rest day or holiday,
     * the remainder going to CTO rather than pay.
     */
    public function test_premium_day_overtime_is_capped_at_twelve_hours(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', [
            'status' => WorkdayStatus::Off,
            'premium' => Premium::Rest,
            'excess' => 800,
        ]);
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(720, $ledger->view(Period::Full)->overtime);
        $this->assertSame(800, $ledger->view(Period::Full)->excess);
    }

    public function test_ordinary_day_overtime_is_not_capped_at_twelve_hours(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['excess' => 800]);
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(800, $ledger->view(Period::Full)->overtime);
    }

    /**
     * Overtime never offsets undertime (§10.4, Rule XVII §9). Excess on the
     * workday does not reduce the undertime total, and compensable overtime
     * is a separate figure.
     */
    public function test_overtime_does_not_offset_undertime(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['undertime' => 60, 'excess' => 180]);
        $this->authority($ledger, '2026-09-01');

        $view = $ledger->view(Period::Full);

        $this->assertSame(60, $view->undertime);
        $this->assertSame(180, $view->overtime);
    }

    /**
     * An overnight authority filed on the 1st is what authorises minutes
     * whose clock time sits on the 2nd. overlapping(), not startingOn().
     */
    public function test_an_overnight_authority_covers_both_calendar_dates(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['excess' => 180]);
        $this->workday($ledger, '2026-09-02', ['excess' => 180]);
        Overtime::factory()->overnight()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'starts' => '2026-09-01 22:00:00',
            'ends' => '2026-09-02 02:00:00',
        ]);

        $this->assertSame(360, $ledger->view(Period::Full)->overtime);
    }

    /**
     * Decision 72 and 75: the weekly component is never authority-gated, and
     * it belongs to the month containing the week's Sunday. 4×12 + 8 over
     * the ISO week of 31 August 2026 is 56 hours against a 48-hour ceiling,
     * so weekly-only is 8 hours. Monday sits on the August ledger; Sunday
     * is 6 September, so September reports those minutes and August does
     * not — even though the week begins in August (decision 52: the ceiling
     * is a property of the week).
     */
    public function test_weekly_overtime_includes_workdays_from_a_neighbouring_ledger(): void
    {
        $ledger = $this->ledger(['overtime_after_weekly' => 48]);
        $august = Ledger::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'month' => '2026-08-01',
        ]);
        $this->workday($august, '2026-08-31', ['worked' => 720]);
        $this->workday($ledger, '2026-09-01', ['worked' => 720]);
        $this->workday($ledger, '2026-09-02', ['worked' => 720]);
        $this->workday($ledger, '2026-09-03', ['worked' => 720]);
        $this->workday($ledger, '2026-09-04', ['worked' => 480]);

        $this->assertSame(480, $ledger->view(Period::Full)->overtime);
        $this->assertSame(0, $august->view(Period::Full)->overtime);
        $this->assertSame(0, $ledger->view(Period::Full, Work::Regular)->overtime);
    }

    /**
     * Decision 75: a week that straddles a month end is reported once, by
     * the month containing its Sunday. The week of 28 September 2026 ends
     * on 4 October, so October reports the 8 hours of weekly-only and
     * September reports none of it — otherwise the same figure is printed
     * on two DTRs and paid twice. September's last partial week is the
     * next month's to report.
     */
    public function test_a_straddling_week_is_reported_only_by_the_month_containing_its_sunday(): void
    {
        $september = $this->ledger(['overtime_after_weekly' => 48]);
        $october = Ledger::factory()->create([
            'agency_id' => $september->agency_id,
            'employee_id' => $september->employee_id,
            'month' => '2026-10-01',
        ]);
        $this->workday($september, '2026-09-28', ['worked' => 720]);
        $this->workday($september, '2026-09-29', ['worked' => 720]);
        $this->workday($september, '2026-09-30', ['worked' => 720]);
        $this->workday($october, '2026-10-01', ['worked' => 720]);
        $this->workday($october, '2026-10-02', ['worked' => 480]);

        $this->assertSame(480, $october->view(Period::Full)->overtime);
        $this->assertSame(0, $september->view(Period::Full)->overtime);
    }

    /**
     * Decision 76: the same last-day rule at the period's resolution. The
     * week of 14–20 September 2026 ends on the 20th, inside the month, so a
     * month filter lets both halves walk it. First-half payroll runs on the
     * 15th, when days 16–20 have not happened — reporting that week there
     * is a figure derived from the future.
     */
    public function test_a_week_ending_on_the_20th_is_reported_only_by_the_second_half(): void
    {
        $ledger = $this->ledger(['overtime_after_weekly' => 48]);
        $this->workday($ledger, '2026-09-14', ['worked' => 720]);
        $this->workday($ledger, '2026-09-15', ['worked' => 720]);
        $this->workday($ledger, '2026-09-16', ['worked' => 720]);
        $this->workday($ledger, '2026-09-17', ['worked' => 720]);
        $this->workday($ledger, '2026-09-18', ['worked' => 480]);

        $this->assertSame(480, $ledger->view(Period::Second)->overtime);
        $this->assertSame(0, $ledger->view(Period::First)->overtime);
    }

    /**
     * Decision 76: filtering on the period's bounds partitions the year
     * at every resolution, so the three views stay consistent with each
     * other. The week ending 13 September belongs to First; the week
     * ending 20 September belongs to Second; Full is their sum.
     */
    public function test_full_weekly_overtime_equals_first_plus_second(): void
    {
        $ledger = $this->ledger(['overtime_after_weekly' => 48]);
        $this->workday($ledger, '2026-09-07', ['worked' => 720]);
        $this->workday($ledger, '2026-09-08', ['worked' => 720]);
        $this->workday($ledger, '2026-09-09', ['worked' => 720]);
        $this->workday($ledger, '2026-09-10', ['worked' => 720]);
        $this->workday($ledger, '2026-09-11', ['worked' => 480]);
        $this->workday($ledger, '2026-09-14', ['worked' => 720]);
        $this->workday($ledger, '2026-09-15', ['worked' => 720]);
        $this->workday($ledger, '2026-09-16', ['worked' => 720]);
        $this->workday($ledger, '2026-09-17', ['worked' => 720]);
        $this->workday($ledger, '2026-09-18', ['worked' => 480]);

        $first = $ledger->view(Period::First)->overtime;
        $second = $ledger->view(Period::Second)->overtime;
        $full = $ledger->view(Period::Full)->overtime;

        $this->assertSame(480, $first);
        $this->assertSame(480, $second);
        $this->assertSame($first + $second, $full);
    }

    /**
     * Daily excess is gated; weekly-only is not. Friday has 180 unauthorised
     * extra minutes, so they are not daily overtime, but the week is still
     * 56 hours of (worked + credited) plus those 180 of excess sitting
     * outside the total: weekly-only is max(0, 3360 − 2880 − 180) = 300.
     *
     * Adding Week::overtime() instead of weeklyOnly() would report 480 and
     * smuggle the unauthorised daily minutes in through the weekly door.
     */
    public function test_unauthorised_daily_excess_is_not_added_through_the_weekly_component(): void
    {
        $ledger = $this->ledger(['overtime_after_weekly' => 48]);
        $august = Ledger::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'month' => '2026-08-01',
        ]);
        $this->workday($august, '2026-08-31', ['worked' => 720]);
        $this->workday($ledger, '2026-09-01', ['worked' => 720]);
        $this->workday($ledger, '2026-09-02', ['worked' => 720]);
        $this->workday($ledger, '2026-09-03', ['worked' => 720]);
        $this->workday($ledger, '2026-09-04', ['worked' => 480, 'excess' => 180]);

        $this->assertSame(300, $ledger->view(Period::Full)->overtime);
    }

    public function test_authorised_daily_excess_adds_to_weekly_only_not_week_overtime(): void
    {
        $ledger = $this->ledger(['overtime_after_weekly' => 48]);
        $august = Ledger::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'month' => '2026-08-01',
        ]);
        $this->workday($august, '2026-08-31', ['worked' => 720]);
        $this->workday($ledger, '2026-09-01', ['worked' => 720]);
        $this->workday($ledger, '2026-09-02', ['worked' => 720]);
        $this->workday($ledger, '2026-09-03', ['worked' => 720]);
        $this->workday($ledger, '2026-09-04', ['worked' => 480, 'excess' => 180]);
        $this->authority($ledger, '2026-09-04');

        $this->assertSame(480, $ledger->view(Period::Full)->overtime);
    }

    public function test_weekly_overtime_is_not_added_when_the_ceiling_is_unbound(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['worked' => 720]);
        $this->workday($ledger, '2026-09-02', ['worked' => 720]);
        $this->workday($ledger, '2026-09-03', ['worked' => 720]);
        $this->workday($ledger, '2026-09-04', ['worked' => 720]);
        $this->workday($ledger, '2026-09-07', ['worked' => 480]);

        $this->assertSame(0, $ledger->view(Period::Full)->overtime);
    }

    /**
     * Decision 52: a stored total is the Timetable mistake. view() is a
     * read. Inserts from factories above the listen are arrange, not the
     * call under test.
     */
    public function test_view_issues_no_writes(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['worked' => 480, 'excess' => 180]);
        $this->authority($ledger, '2026-09-01');

        $writes = 0;
        DB::listen(function (object $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes++;
            }
        });

        $ledger->view(Period::Full);

        $this->assertSame(0, $writes);
    }

    /**
     * The workdays load once with what they need; no query inside a per-day
     * loop. Fifteen days in the first half touch three ISO weeks, one day
     * touches one — the query count must not follow either number.
     */
    public function test_loading_many_days_issues_the_same_queries_as_one(): void
    {
        $this->agency->update(['settings' => ['overtime_after_weekly' => 48]]);
        $this->agency->refresh();

        $one = $this->ledger();
        $this->workday($one, '2026-09-15', ['worked' => 480, 'excess' => 180]);
        $this->authority($one, '2026-09-15');

        $many = $this->ledger();
        foreach (range(1, 15) as $day) {
            $date = sprintf('2026-09-%02d', $day);
            $this->workday($many, $date, ['worked' => 480, 'excess' => 180]);
        }
        $this->authority($many, '2026-09-01');

        $queries = 0;
        $counting = false;
        DB::listen(function () use (&$queries, &$counting): void {
            if ($counting) {
                $queries++;
            }
        });

        $counting = true;
        $one->view(Period::First);
        $counting = false;
        $few = $queries;

        $queries = 0;
        $counting = true;
        $many->view(Period::First);
        $counting = false;

        $this->assertSame($few, $queries);
        $this->assertGreaterThan(0, $few);
    }
}

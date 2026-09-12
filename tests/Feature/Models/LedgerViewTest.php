<?php

namespace Tests\Feature\Models;

use App\Attendance\LedgerView;
use App\Enums\MissingSide;
use App\Enums\Period;
use App\Enums\Premium;
use App\Enums\PunchKind;
use App\Enums\Work;
use App\Enums\WorkdayStatus;
use App\Models\Agency;
use App\Models\Exemption;
use App\Models\Ledger;
use App\Models\Overtime;
use App\Models\Punch;
use App\Models\Workday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ledger::view() — the month read back (06-attendance.md Ledger rules 1–2,
 * daily rules 6, 9 and 11; 05-calendar.md rule 6; decisions 52 and 72).
 *
 * Live calculations run on unsaved range objects; locking freezes these
 * results in a separate immutable revision.
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

        return Ledger::factory()->make([
            'agency_id' => $this->agency->id,
            'starts' => '2026-09-01',
            'ends' => '2026-09-30',
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

    private function window(Ledger $ledger, string $starts, string $ends): Overtime
    {
        return Overtime::factory()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'starts' => $starts,
            'ends' => $ends,
        ]);
    }

    private function punched(Workday $workday, ?string $expectedIn, ?string $expectedOut, string $in, string $out): void
    {
        foreach ([[PunchKind::In, $expectedIn, $in], [PunchKind::Out, $expectedOut, $out]] as [$kind, $expected, $actual]) {
            Punch::factory()->create([
                'agency_id' => $workday->agency_id,
                'employee_id' => $workday->employee_id,
                'workday_id' => $workday->id,
                'slot' => 1,
                'kind' => $kind,
                'expected_at' => $expected,
                'actual_at' => $actual,
                'deviation' => $expected === null
                    ? null
                    : (int) CarbonImmutable::parse($expected)->diffInMinutes(CarbonImmutable::parse($actual), false),
            ]);
        }
    }

    /**
     * @return list<string>
     */
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
        $workday = $this->workday($ledger, '2026-09-01', ['worked' => 480, 'excess' => 180]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');
        $this->authority($ledger, '2026-09-01');

        $view = $ledger->view(Period::Full);

        $this->assertSame(480, $view->worked);
        $this->assertSame(180, $view->overtime);
        $this->assertSame(['2026-09-01' => 180], $view->overtimeByDate);
        $this->assertSame(180, $view->workdays->first()->overtime);
    }

    public function test_regular_work_reports_overtime_as_zero(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['worked' => 480, 'excess' => 180]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');
        $this->authority($ledger, '2026-09-01');

        $view = $ledger->view(Period::Full, Work::Regular);

        $this->assertSame(480, $view->worked);
        $this->assertSame(180, $view->excess);
        $this->assertSame(0, $view->overtime);
        $this->assertSame([], $view->overtimeByDate);
        $this->assertSame(0, $view->workdays->first()->overtime);
    }

    public function test_overtime_work_includes_compensable_overtime(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['worked' => 480, 'excess' => 180]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(180, $ledger->view(Period::Full, Work::Overtime)->overtime);
    }

    public function test_excess_inside_the_authority_is_compensable(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['excess' => 180]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(180, $ledger->view(Period::Full)->overtime);
    }

    public function test_an_authority_covering_part_of_the_excess_compensates_only_that_part(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['excess' => 180]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');
        $this->window($ledger, '2026-09-01 17:00:00', '2026-09-01 19:00:00');

        $this->assertSame(120, $ledger->view(Period::Full)->overtime);
    }

    public function test_an_authority_inside_the_expected_hours_compensates_nothing(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['excess' => 180]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');
        $this->window($ledger, '2026-09-01 09:00:00', '2026-09-01 11:00:00');

        $this->assertSame(0, $ledger->view(Period::Full)->overtime);
    }

    public function test_the_assume_policy_is_read_from_the_frozen_snapshot(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['excess' => 180]);
        $snapshot = $workday->shift;
        $snapshot['settings']['missing_side'] = MissingSide::Assume->value;
        $workday->update(['shift' => $snapshot]);

        // The morning tap never happened; only the 20:00 departure did.
        Punch::factory()->missed()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'slot' => 1,
            'kind' => PunchKind::In,
            'expected_at' => '2026-09-01 08:00:00',
        ]);
        Punch::factory()->create([
            'agency_id' => $workday->agency_id,
            'employee_id' => $workday->employee_id,
            'workday_id' => $workday->id,
            'slot' => 1,
            'kind' => PunchKind::Out,
            'expected_at' => '2026-09-01 17:00:00',
            'actual_at' => '2026-09-01 20:00:00',
            'deviation' => 180,
        ]);
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(180, $ledger->view(Period::Full)->overtime);
    }

    public function test_ungated_overtime_is_the_whole_excess_with_no_authority(): void
    {
        $ledger = $this->ledger(['overtime_gates' => false]);
        $workday = $this->workday($ledger, '2026-09-01', ['tardy' => 30, 'excess' => 90]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:30:00', '2026-09-01 18:30:00');

        $view = $ledger->view(Period::Full);

        $this->assertSame(90, $view->overtime);
        $this->assertSame(30, $view->tardy);
    }

    public function test_ungated_overtime_is_not_capped_on_a_premium_day(): void
    {
        $ledger = $this->ledger(['overtime_gates' => false]);
        $workday = $this->workday($ledger, '2026-09-01', [
            'status' => WorkdayStatus::Off,
            'premium' => Premium::Rest,
            'excess' => 800,
        ]);
        $this->punched($workday, null, null, '2026-09-01 06:00:00', '2026-09-01 19:20:00');

        $this->assertSame(800, $ledger->view(Period::Full)->overtime);
    }

    public function test_ungated_overtime_still_excludes_a_zeroed_day(): void
    {
        $ledger = $this->ledger(['overtime_gates' => false]);
        $workday = $this->workday($ledger, '2026-09-01', ['excess' => 0]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');

        $this->assertSame(0, $ledger->view(Period::Full)->overtime);
    }

    public function test_ungated_overtime_is_still_suppressed_by_regular_work(): void
    {
        $ledger = $this->ledger(['overtime_gates' => false]);
        $workday = $this->workday($ledger, '2026-09-01', ['excess' => 180]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');

        $this->assertSame(0, $ledger->view(Period::Full, Work::Regular)->overtime);
    }

    public function test_a_day_whose_excess_was_zeroed_compensates_nothing(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['excess' => 0]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(0, $ledger->view(Period::Full)->overtime);
    }

    public function test_an_authority_that_misses_the_excess_compensates_nothing(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['excess' => 180]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');
        $this->window($ledger, '2026-09-01 05:00:00', '2026-09-01 07:00:00');

        $this->assertSame(0, $ledger->view(Period::Full)->overtime);
    }

    public function test_excess_is_not_compensable_without_an_authority(): void
    {
        $ledger = $this->ledger();
        $this->workday($ledger, '2026-09-01', ['excess' => 180]);

        $this->assertSame(0, $ledger->view(Period::Full)->overtime);
        $this->assertSame(180, $ledger->view(Period::Full)->excess);
    }

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
        $workday = $this->workday($ledger, '2026-09-01', ['excess' => 120]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 19:00:00');
        $this->authority($ledger, '2026-09-01');

        $this->assertSame(120, $ledger->view(Period::Full)->overtime);
    }

    public function test_premium_day_overtime_is_capped_at_twelve_hours(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', [
            'status' => WorkdayStatus::Off,
            'premium' => Premium::Rest,
            'excess' => 800,
        ]);
        // Decision 78: a rest day's taps answer no expectation, so the whole
        // of the presence is excess.
        $this->punched($workday, null, null, '2026-09-01 06:00:00', '2026-09-01 19:20:00');
        $this->window($ledger, '2026-09-01 06:00:00', '2026-09-01 19:20:00');

        $this->assertSame(720, $ledger->view(Period::Full)->overtime);
        $this->assertSame(800, $ledger->view(Period::Full)->excess);
    }

    public function test_the_credited_minutes_of_a_premium_day_are_not_authorisable(): void
    {
        $ledger = $this->ledger(['premium_hours' => true]);
        $workday = $this->workday($ledger, '2026-09-01', [
            'status' => WorkdayStatus::Off,
            'premium' => Premium::Rest,
            'credited' => 480,
            'excess' => 240,
        ]);
        $this->punched($workday, null, null, '2026-09-01 08:00:00', '2026-09-01 20:00:00');
        $this->window($ledger, '2026-09-01 09:00:00', '2026-09-01 11:00:00');

        $this->assertSame(0, $ledger->view(Period::Full)->overtime);
    }

    public function test_ordinary_day_overtime_is_not_capped_at_twelve_hours(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['excess' => 800]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 09:00:00', '2026-09-01 08:00:00', '2026-09-01 22:20:00');
        $this->window($ledger, '2026-09-01 09:00:00', '2026-09-01 22:20:00');

        $this->assertSame(800, $ledger->view(Period::Full)->overtime);
    }

    public function test_overtime_does_not_offset_undertime(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['undertime' => 60, 'excess' => 180]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');
        $this->authority($ledger, '2026-09-01');

        $view = $ledger->view(Period::Full);

        $this->assertSame(60, $view->undertime);
        $this->assertSame(180, $view->overtime);
    }

    public function test_an_overnight_authority_reaches_minutes_on_both_calendar_dates(): void
    {
        $ledger = $this->ledger();
        $first = $this->workday($ledger, '2026-09-01', ['excess' => 180]);
        $second = $this->workday($ledger, '2026-09-02', ['excess' => 180]);
        $this->punched($first, '2026-09-01 14:00:00', '2026-09-01 22:00:00', '2026-09-01 14:00:00', '2026-09-02 01:00:00');
        $this->punched($second, '2026-09-02 02:00:00', '2026-09-02 10:00:00', '2026-09-01 23:00:00', '2026-09-02 10:00:00');
        Overtime::factory()->overnight()->create([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'starts' => '2026-09-01 22:00:00',
            'ends' => '2026-09-02 02:00:00',
        ]);

        $this->assertSame(360, $ledger->view(Period::Full)->overtime);
    }

    public function test_weekly_overtime_includes_workdays_from_a_neighbouring_ledger(): void
    {
        $ledger = $this->ledger(['overtime_after_weekly' => 48]);
        $august = Ledger::factory()->make([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'starts' => '2026-08-01',
            'ends' => '2026-08-31',
        ]);
        $this->workday($august, '2026-08-31', ['worked' => 720]);
        $this->workday($ledger, '2026-09-01', ['worked' => 720]);
        $this->workday($ledger, '2026-09-02', ['worked' => 720]);
        $this->workday($ledger, '2026-09-03', ['worked' => 720]);
        $this->workday($ledger, '2026-09-04', ['worked' => 480]);

        $view = $ledger->view(Period::Full);

        $this->assertSame(480, $view->overtime);
        $this->assertSame(['2026-09-06' => 480], $view->overtimeByDate);
        $this->assertSame(0, $august->view(Period::Full)->overtime);
        $this->assertSame(0, $ledger->view(Period::Full, Work::Regular)->overtime);
    }

    public function test_a_straddling_week_is_reported_only_by_the_month_containing_its_sunday(): void
    {
        $september = $this->ledger(['overtime_after_weekly' => 48]);
        $october = Ledger::factory()->make([
            'agency_id' => $september->agency_id,
            'employee_id' => $september->employee_id,
            'starts' => '2026-10-01',
            'ends' => '2026-10-31',
        ]);
        $this->workday($september, '2026-09-28', ['worked' => 720]);
        $this->workday($september, '2026-09-29', ['worked' => 720]);
        $this->workday($september, '2026-09-30', ['worked' => 720]);
        $this->workday($october, '2026-10-01', ['worked' => 720]);
        $this->workday($october, '2026-10-02', ['worked' => 480]);

        $this->assertSame(480, $october->view(Period::Full)->overtime);
        $this->assertSame(0, $september->view(Period::Full)->overtime);
    }

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

    public function test_unauthorised_daily_excess_is_not_added_through_the_weekly_component(): void
    {
        $ledger = $this->ledger(['overtime_after_weekly' => 48]);
        $august = Ledger::factory()->make([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'starts' => '2026-08-01',
            'ends' => '2026-08-31',
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
        $august = Ledger::factory()->make([
            'agency_id' => $ledger->agency_id,
            'employee_id' => $ledger->employee_id,
            'starts' => '2026-08-01',
            'ends' => '2026-08-31',
        ]);
        $this->workday($august, '2026-08-31', ['worked' => 720]);
        $this->workday($ledger, '2026-09-01', ['worked' => 720]);
        $this->workday($ledger, '2026-09-02', ['worked' => 720]);
        $this->workday($ledger, '2026-09-03', ['worked' => 720]);
        $fourth = $this->workday($ledger, '2026-09-04', ['worked' => 480, 'excess' => 180]);
        $this->punched($fourth, '2026-09-04 08:00:00', '2026-09-04 17:00:00', '2026-09-04 08:00:00', '2026-09-04 20:00:00');
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

    public function test_view_issues_no_writes(): void
    {
        $ledger = $this->ledger();
        $workday = $this->workday($ledger, '2026-09-01', ['worked' => 480, 'excess' => 180]);
        $this->punched($workday, '2026-09-01 08:00:00', '2026-09-01 17:00:00', '2026-09-01 08:00:00', '2026-09-01 20:00:00');
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

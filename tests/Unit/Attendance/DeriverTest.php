<?php

namespace Tests\Unit\Attendance;

use App\Attendance\Day;
use App\Attendance\Derived;
use App\Attendance\Deriver;
use App\Attendance\Expectation;
use App\Attendance\Matching;
use App\Enums\MissingSide;
use App\Enums\Premium;
use App\Enums\PunchKind;
use App\Enums\WorkdayStatus;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The day's figures: status and the seven minute columns
 * (06-attendance.md daily rules 1 to 10). A unit test and not a feature
 * test: Deriver is pure over Day, Matching and settings, so the worked
 * example in the same document can run without a workday row.
 *
 * 8 September 2026 is the date that example names.
 */
class DeriverTest extends TestCase
{
    private function date(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-08');
    }

    private function at(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime);
    }

    private function shift(int $required = 480): Shift
    {
        return new Shift(['required' => $required]);
    }

    /**
     * @return list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>
     */
    private function standard(): array
    {
        return Expectation::sides([
            'slots' => [
                ['in' => '08:00', 'out' => '12:00', 'window' => [-240, 180]],
                ['in' => '13:00', 'out' => '17:00', 'window' => [-120, 300]],
            ],
        ], $this->date());
    }

    /**
     * @return list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>
     */
    private function flexi(): array
    {
        return Expectation::sides([
            'slots' => [
                ['in' => '07:00', 'out' => '11:00', 'window' => [-30, 240]],
                ['in' => '12:00', 'out' => '16:00', 'window' => [-60, 360]],
            ],
        ], $this->date());
    }

    /**
     * @return array{slot: int, kind: string, expected_at: CarbonImmutable, timelog_id: ?string, actual_at: ?CarbonImmutable, deviation: ?int}
     */
    private function punch(int $slot, string $kind, string $expected, ?string $actual, ?string $timelogId = 'tl'): array
    {
        $expectedAt = $this->at($expected);
        $actualAt = $actual === null ? null : $this->at($actual);

        return [
            'slot' => $slot,
            'kind' => $kind,
            'expected_at' => $expectedAt,
            'timelog_id' => $actualAt === null ? null : $timelogId.$slot.$kind,
            'actual_at' => $actualAt,
            'deviation' => $actualAt === null ? null : (int) round($expectedAt->diffInMinutes($actualAt, false)),
        ];
    }

    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  list<array{slot: int, kind: string, expected_at: CarbonImmutable, timelog_id: ?string, actual_at: ?CarbonImmutable, deviation: ?int}>  $punches
     */
    private function matching(array $sides, array $punches): Matching
    {
        return new Matching($sides, $punches);
    }

    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $excused
     */
    private function day(
        ?Shift $shift,
        array $sides = [],
        ?WorkdayStatus $status = null,
        ?Premium $premium = null,
        array $excused = [],
        bool $travel = false,
        string $nightFrom = '18:00',
    ): Day {
        return new Day(
            date: $this->date(),
            shift: $shift,
            sides: $sides,
            status: $status,
            premium: $premium,
            excused: $excused,
            travel: $travel,
            exemptionId: null,
            nightFrom: $nightFrom,
        );
    }

    private function derive(
        Day $day,
        Matching $matching,
        MissingSide $missingSide = MissingSide::Void,
        bool $premiumHours = false,
        bool $suspensionCharge = true,
        bool $precedingUnexcusedAbsence = false,
    ): Derived {
        return Deriver::derive(
            $day,
            $matching,
            $missingSide,
            $premiumHours,
            $suspensionCharge,
            $precedingUnexcusedAbsence,
        );
    }

    private function assertFigures(
        Derived $derived,
        WorkdayStatus $status,
        int $worked,
        int $credited,
        int $tardy,
        int $undertime,
        int $excess,
        int $night = 0,
        int $nightExcess = 0,
    ): void {
        $this->assertSame($status, $derived->status);
        $this->assertSame($worked, $derived->worked);
        $this->assertSame($credited, $derived->credited);
        $this->assertSame($tardy, $derived->tardy);
        $this->assertSame($undertime, $derived->undertime);
        $this->assertSame($excess, $derived->excess);
        $this->assertSame($night, $derived->night);
        $this->assertSame($nightExcess, $derived->nightExcess);
    }

    /**
     * 06-attendance.md chain, 8 September. Afternoon in missing, out at
     * 17:05. Under Void the slot is not credited (worked 240) and the
     * missing in contributes no undertime (decision 71). Excess 5 is the
     * morning's 07:58–08:00 and 12:00–12:03, not a charge against 17:05.
     */
    public function test_the_standard_worked_example_charges_the_punched_side_only(): void
    {
        $sides = $this->standard();
        $matching = $this->matching($sides, [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', '2026-09-08 07:58:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', '2026-09-08 12:03:00'),
            $this->punch(2, PunchKind::In->value, '2026-09-08 13:00:00', null),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 17:00:00', '2026-09-08 17:05:00'),
        ]);

        $derived = $this->derive($this->day($this->shift(), $sides), $matching);

        $this->assertFigures($derived, WorkdayStatus::Present, 240, 0, 0, 0, 5);
    }

    /**
     * Assume credits the half-filled slot to its expected span and still
     * does not invent tardiness for the missing in (decision 64, 71).
     */
    public function test_assume_credits_a_half_filled_slot_without_charging_the_missing_side(): void
    {
        $sides = $this->standard();
        $matching = $this->matching($sides, [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', '2026-09-08 07:58:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', '2026-09-08 12:03:00'),
            $this->punch(2, PunchKind::In->value, '2026-09-08 13:00:00', null),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 17:00:00', '2026-09-08 17:05:00'),
        ]);

        $derived = $this->derive($this->day($this->shift(), $sides), $matching, MissingSide::Assume);

        $this->assertFigures($derived, WorkdayStatus::Present, 480, 0, 0, 0, 10);
    }

    /**
     * Daily rule 2 / MC 17 s. 2010: a morning with no punches and an
     * afternoon present is one tardy occurrence carrying the morning.
     */
    public function test_a_morning_with_no_punches_and_an_afternoon_present_is_one_tardy_occurrence(): void
    {
        $sides = $this->standard();
        $matching = $this->matching($sides, [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', null),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', null),
            $this->punch(2, PunchKind::In->value, '2026-09-08 13:00:00', '2026-09-08 13:00:00'),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 17:00:00', '2026-09-08 17:00:00'),
        ]);

        $derived = $this->derive($this->day($this->shift(), $sides), $matching);

        $this->assertFigures($derived, WorkdayStatus::Present, 240, 0, 240, 0, 0);
    }

    /**
     * Daily rule 3 / MC 17 s. 2010: an afternoon with no punches and a
     * morning present is one undertime occurrence carrying the afternoon.
     */
    public function test_an_afternoon_with_no_punches_and_a_morning_present_is_one_undertime_occurrence(): void
    {
        $sides = $this->standard();
        $matching = $this->matching($sides, [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', '2026-09-08 08:00:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', '2026-09-08 12:00:00'),
            $this->punch(2, PunchKind::In->value, '2026-09-08 13:00:00', null),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 17:00:00', null),
        ]);

        $derived = $this->derive($this->day($this->shift(), $sides), $matching);

        $this->assertFigures($derived, WorkdayStatus::Present, 240, 0, 0, 240, 0);
    }

    /** Rule 5: regular holiday → required, unless the preceding work day was an unexcused absence. */
    public function test_a_regular_holiday_credits_required(): void
    {
        $derived = $this->derive(
            $this->day($this->shift(), [], WorkdayStatus::Holiday, Premium::Regular),
            $this->matching([], []),
        );

        $this->assertFigures($derived, WorkdayStatus::Holiday, 480, 0, 0, 0, 0);
    }

    public function test_a_regular_holiday_credits_nothing_after_an_unexcused_absence(): void
    {
        $derived = $this->derive(
            $this->day($this->shift(), [], WorkdayStatus::Holiday, Premium::Regular),
            $this->matching([], []),
            precedingUnexcusedAbsence: true,
        );

        $this->assertFigures($derived, WorkdayStatus::Holiday, 0, 0, 0, 0, 0);
    }

    /**
     * The single most likely bug: one branch on "the expectation is empty"
     * giving required to a special day. No work, no pay.
     */
    public function test_a_special_day_credits_nothing(): void
    {
        $derived = $this->derive(
            $this->day($this->shift(), [], WorkdayStatus::Holiday, Premium::Special),
            $this->matching([], []),
        );

        $this->assertFigures($derived, WorkdayStatus::Holiday, 0, 0, 0, 0, 0);
    }

    public function test_a_rest_day_credits_nothing(): void
    {
        $derived = $this->derive(
            $this->day($this->shift(0), [], WorkdayStatus::Off, Premium::Rest),
            $this->matching([], []),
        );

        $this->assertFigures($derived, WorkdayStatus::Off, 0, 0, 0, 0, 0);
    }

    /**
     * Premium is checked before status: a regular holiday on a rest day is
     * off and regular, and worked follows the premium (decision 62).
     */
    public function test_a_regular_holiday_on_a_rest_day_follows_the_premium(): void
    {
        $derived = $this->derive(
            $this->day($this->shift(), [], WorkdayStatus::Off, Premium::Regular),
            $this->matching([], []),
        );

        $this->assertFigures($derived, WorkdayStatus::Off, 480, 0, 0, 0, 0);
    }

    public function test_a_whole_day_suspension_gives_zero_worked_when_suspension_charge_is_true(): void
    {
        $derived = $this->derive(
            $this->day($this->shift(), [], WorkdayStatus::Suspended),
            $this->matching([], []),
            suspensionCharge: true,
        );

        $this->assertFigures($derived, WorkdayStatus::Suspended, 0, 0, 0, 0, 0);
    }

    public function test_a_whole_day_suspension_gives_required_when_suspension_charge_is_false(): void
    {
        $derived = $this->derive(
            $this->day($this->shift(), [], WorkdayStatus::Suspended),
            $this->matching([], []),
            suspensionCharge: false,
        );

        $this->assertFigures($derived, WorkdayStatus::Suspended, 480, 0, 0, 0, 0);
    }

    public function test_a_remote_day_credits_required(): void
    {
        $derived = $this->derive(
            $this->day($this->shift(), [], WorkdayStatus::Remote),
            $this->matching([], []),
        );

        $this->assertFigures($derived, WorkdayStatus::Remote, 480, 0, 0, 0, 0);
    }

    public function test_no_roster_credits_nothing(): void
    {
        $derived = $this->derive(
            $this->day(null, [], WorkdayStatus::Off),
            $this->matching([], []),
        );

        $this->assertFigures($derived, WorkdayStatus::Off, 0, 0, 0, 0, 0);
    }

    public function test_an_ordinary_on_time_day_is_worked_required(): void
    {
        $sides = $this->standard();
        $matching = $this->matching($sides, [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', '2026-09-08 08:00:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', '2026-09-08 12:00:00'),
            $this->punch(2, PunchKind::In->value, '2026-09-08 13:00:00', '2026-09-08 13:00:00'),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 17:00:00', '2026-09-08 17:00:00'),
        ]);

        $derived = $this->derive($this->day($this->shift(), $sides), $matching);

        $this->assertFigures($derived, WorkdayStatus::Present, 480, 0, 0, 0, 0);
    }

    /**
     * Rule 10 / decision 51: credited is the first 480 of actual attendance
     * on a premium day whose expectation is empty, and the rest stays excess.
     * 480 is not the shift's required.
     */
    public function test_credited_is_the_first_480_of_presence_on_a_premium_day(): void
    {
        $matching = $this->matching([], [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', '2026-09-08 08:00:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', '2026-09-08 12:00:00'),
            $this->punch(2, PunchKind::In->value, '2026-09-08 12:00:00', '2026-09-08 12:00:00'),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 20:00:00', '2026-09-08 20:00:00'),
        ]);

        $derived = $this->derive(
            $this->day($this->shift(600), [], WorkdayStatus::Off, Premium::Rest),
            $matching,
            premiumHours: true,
        );

        $this->assertFigures($derived, WorkdayStatus::Off, 0, 480, 0, 0, 240, 0, 120);
    }

    public function test_credited_stays_zero_when_premium_hours_is_false(): void
    {
        $matching = $this->matching([], [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', '2026-09-08 08:00:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 16:00:00', '2026-09-08 16:00:00'),
        ]);

        $derived = $this->derive(
            $this->day($this->shift(), [], WorkdayStatus::Holiday, Premium::Regular),
            $matching,
            premiumHours: false,
        );

        $this->assertFigures($derived, WorkdayStatus::Holiday, 480, 0, 0, 0, 480);
    }

    /**
     * Rule 7: travel zeroes excess and leaves nightExcess. Worked and
     * credited still follow the premium table.
     */
    public function test_travel_zeroes_excess_and_leaves_night_excess(): void
    {
        $matching = $this->matching([], [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', '2026-09-08 08:00:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', '2026-09-08 12:00:00'),
            $this->punch(2, PunchKind::In->value, '2026-09-08 12:00:00', '2026-09-08 12:00:00'),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 20:00:00', '2026-09-08 20:00:00'),
        ]);

        $derived = $this->derive(
            $this->day($this->shift(0), [], WorkdayStatus::Off, Premium::Rest, [], true),
            $matching,
            premiumHours: true,
        );

        $this->assertFigures($derived, WorkdayStatus::Off, 0, 480, 0, 0, 0, 0, 120);
    }

    /**
     * Excusing is a set subtraction applied last. Two overlapping windows
     * covering a 30-minute late arrival leave only the uncovered tail.
     */
    public function test_exemptions_subtract_from_tardy_as_sets(): void
    {
        $sides = $this->standard();
        $matching = $this->matching($sides, [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', '2026-09-08 08:30:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', '2026-09-08 12:00:00'),
            $this->punch(2, PunchKind::In->value, '2026-09-08 13:00:00', '2026-09-08 13:00:00'),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 17:00:00', '2026-09-08 17:00:00'),
        ]);
        $excused = [
            [$this->at('2026-09-08 08:00:00'), $this->at('2026-09-08 08:20:00')],
            [$this->at('2026-09-08 08:10:00'), $this->at('2026-09-08 08:25:00')],
        ];

        $derived = $this->derive($this->day($this->shift(), $sides, excused: $excused), $matching);

        $this->assertSame(5, $derived->tardy);
        $this->assertSame(0, $derived->undertime);
    }

    /**
     * Flexitime has already moved matching.sides. Tardiness against
     * $day->sides would charge an arrival inside the band.
     */
    public function test_tardy_uses_the_slid_sides_not_the_unslid_day(): void
    {
        $unslid = $this->flexi();
        $slid = [];

        foreach ($unslid as $side) {
            $side['at'] = $side['at']->addMinutes(83);
            $slid[] = $side;
        }

        $matching = $this->matching($slid, [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:23:00', '2026-09-08 08:23:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:23:00', '2026-09-08 12:23:00'),
            $this->punch(2, PunchKind::In->value, '2026-09-08 13:23:00', '2026-09-08 13:23:00'),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 17:23:00', '2026-09-08 17:23:00'),
        ]);

        $derived = $this->derive($this->day($this->shift(), $unslid), $matching);

        $this->assertFigures($derived, WorkdayStatus::Present, 480, 0, 0, 0, 0);
    }

    public function test_status_is_absent_when_no_punch_has_a_timelog(): void
    {
        $sides = $this->standard();
        $matching = $this->matching($sides, [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', null),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', null),
            $this->punch(2, PunchKind::In->value, '2026-09-08 13:00:00', null),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 17:00:00', null),
        ]);

        $derived = $this->derive($this->day($this->shift(), $sides), $matching);

        $this->assertFigures($derived, WorkdayStatus::Absent, 0, 0, 0, 0, 0);
    }

    public function test_a_calendar_status_is_kept_when_the_day_already_has_one(): void
    {
        $sides = $this->standard();
        $matching = $this->matching($sides, [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', '2026-09-08 08:00:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', '2026-09-08 12:00:00'),
            $this->punch(2, PunchKind::In->value, '2026-09-08 13:00:00', '2026-09-08 13:00:00'),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 17:00:00', '2026-09-08 17:00:00'),
        ]);

        $derived = $this->derive($this->day($this->shift(), $sides, WorkdayStatus::Holiday, Premium::Regular), $matching);

        $this->assertSame(WorkdayStatus::Holiday, $derived->status);
        $this->assertSame(480, $derived->worked);
    }

    /**
     * Rule 6: excess never reduces tardy. Thirty minutes early and thirty
     * minutes late stay thirty of each.
     */
    public function test_excess_does_not_offset_tardy(): void
    {
        $sides = $this->standard();
        $matching = $this->matching($sides, [
            $this->punch(1, PunchKind::In->value, '2026-09-08 08:00:00', '2026-09-08 08:30:00'),
            $this->punch(1, PunchKind::Out->value, '2026-09-08 12:00:00', '2026-09-08 12:00:00'),
            $this->punch(2, PunchKind::In->value, '2026-09-08 13:00:00', '2026-09-08 13:00:00'),
            $this->punch(2, PunchKind::Out->value, '2026-09-08 17:00:00', '2026-09-08 17:30:00'),
        ]);

        $derived = $this->derive($this->day($this->shift(), $sides), $matching);

        $this->assertSame(30, $derived->tardy);
        $this->assertSame(30, $derived->excess);
        $this->assertSame(0, $derived->undertime);
    }
}

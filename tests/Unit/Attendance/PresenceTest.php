<?php

namespace Tests\Unit\Attendance;

use App\Attendance\Presence;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Daily rule 5 as set algebra: worked, excess, night, nightExcess.
 *
 * A unit test and not a feature test: Presence takes ranges and a clock
 * string, so the hospital night shift and Duty24 can be written without
 * a workday row. Policy — holidays, `assume`, `travel` — is the deriver's
 * and is not in these figures.
 */
class PresenceTest extends TestCase
{
    private function date(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-30');
    }

    private function at(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function span(string $from, string $to): array
    {
        return [$this->at($from), $this->at($to)];
    }

    /**
     * 22:00 on 30 September to 06:00 on 1 October — the hospital night
     * shift of 06-attendance.md.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function hospitalNight(): array
    {
        return [$this->span('2026-09-30 22:00:00', '2026-10-01 06:00:00')];
    }

    private function assertFigures(Presence $presence, int $worked, int $excess, int $night, int $nightExcess): void
    {
        $this->assertSame($worked, $presence->worked());
        $this->assertSame($excess, $presence->excess());
        $this->assertSame($night, $presence->night());
        $this->assertSame($nightExcess, $presence->nightExcess());
    }

    /**
     * On time: the entire eight hours sit inside both the expectation
     * and a `'22:00'` window, so night is 480 and nightExcess is 0.
     */
    public function test_the_hospital_night_shift_is_entirely_night(): void
    {
        $shift = $this->hospitalNight();

        $presence = Presence::of($shift, $shift, '22:00', $this->date());

        $this->assertFigures($presence, 480, 0, 480, 0);
    }

    /**
     * The same shift left at 07:30. The extra 90 minutes are excess.
     * They sit after 06:00, so they are outside the night window:
     * nightExcess stays 0, and night + nightExcess is exactly
     * `|presence ∩ nightly|` = 480 (decision 53 — a partition, not two
     * measurements). Computing nightExcess as total-night − night would
     * make this identity tautological; it is asserted against the known
     * window length instead.
     */
    public function test_night_and_night_excess_partition_the_night_window(): void
    {
        $presence = Presence::of(
            [$this->span('2026-09-30 22:00:00', '2026-10-01 07:30:00')],
            $this->hospitalNight(),
            '22:00',
            $this->date(),
        );

        $this->assertFigures($presence, 480, 90, 480, 0);
        $this->assertSame(480, $presence->night() + $presence->nightExcess());
    }

    /**
     * Overtime that straddles 06:00: expected out 05:00, actual out 07:30.
     * nightExcess is its own set operation — the 60 minutes before 06:00 —
     * not `excess` and not `total night − night`.
     */
    public function test_night_excess_is_the_night_minutes_outside_the_expectation(): void
    {
        $presence = Presence::of(
            [$this->span('2026-09-30 22:00:00', '2026-10-01 07:30:00')],
            [$this->span('2026-09-30 22:00:00', '2026-10-01 05:00:00')],
            '22:00',
            $this->date(),
        );

        $this->assertFigures($presence, 420, 150, 420, 60);
        $this->assertSame(480, $presence->night() + $presence->nightExcess());
    }

    /**
     * Duty24: 08:00 to 32:00, one slot, crossing a whole night.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function duty24NightFrom(): array
    {
        return [
            'labor code' => ['22:00', 8 * 60],
            'civil service' => ['18:00', 12 * 60],
        ];
    }

    #[DataProvider('duty24NightFrom')]
    public function test_duty24_crosses_a_whole_night(string $nightFrom, int $night): void
    {
        $duty = [$this->span('2026-09-30 08:00:00', '2026-10-01 08:00:00')];

        $presence = Presence::of($duty, $duty, $nightFrom, $this->date());

        $this->assertFigures($presence, 24 * 60, 0, $night, 0);
        $this->assertSame($night, $presence->night() + $presence->nightExcess());
    }

    /** No presence at all: all four figures are 0, whether or not the day expected work. */
    public function test_a_day_with_no_presence_is_four_zeros(): void
    {
        $this->assertFigures(
            Presence::of([], $this->hospitalNight(), '22:00', $this->date()),
            0,
            0,
            0,
            0,
        );
        $this->assertFigures(
            Presence::of([], [], '22:00', $this->date()),
            0,
            0,
            0,
            0,
        );
    }

    /**
     * Empty expected with presence is a premium day's attendance:
     * worked 0, excess everything, and night minutes fall in nightExcess
     * because the partition is the expectation, not an overtime threshold
     * (decision 53).
     */
    public function test_empty_expected_puts_every_minute_in_excess(): void
    {
        $presence = Presence::of(
            [$this->span('2026-09-30 22:00:00', '2026-10-01 06:00:00')],
            [],
            '22:00',
            $this->date(),
        );

        $this->assertFigures($presence, 0, 480, 0, 480);
        $this->assertSame(480, $presence->night() + $presence->nightExcess());
    }

    /**
     * Two presence ranges that overlap must not double-count the shared
     * minutes. Union both inputs before measuring anything.
     */
    public function test_overlapping_presence_does_not_double_count_worked_minutes(): void
    {
        $presence = Presence::of(
            [
                $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
                $this->span('2026-09-30 11:00:00', '2026-09-30 13:00:00'),
            ],
            [$this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00')],
            '22:00',
            $this->date(),
        );

        $this->assertFigures($presence, 240, 60, 0, 0);
    }

    /**
     * A shift whose pairs abut at 12:00 must not double-count noon.
     * Half-open ranges make that unwriteable.
     */
    public function test_abutting_expected_slots_do_not_double_count_noon(): void
    {
        $presence = Presence::of(
            [$this->span('2026-09-30 08:00:00', '2026-09-30 17:00:00')],
            [
                $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
                $this->span('2026-09-30 12:00:00', '2026-09-30 17:00:00'),
            ],
            '22:00',
            $this->date(),
        );

        $this->assertFigures($presence, 540, 0, 0, 0);
    }

    /**
     * A timelog up to 240 minutes early can land in the night *before*
     * the workday's date. 04:00 on the 30th is inside 22:00–06:00 of
     * the 29th; dropping date−1 would report nightExcess 0.
     */
    public function test_early_presence_counts_the_night_before_the_date(): void
    {
        $presence = Presence::of(
            [$this->span('2026-09-30 04:00:00', '2026-09-30 12:00:00')],
            [$this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00')],
            '22:00',
            $this->date(),
        );

        $this->assertFigures($presence, 240, 240, 0, 120);
        $this->assertSame(120, $presence->night() + $presence->nightExcess());
    }

    /**
     * Roster.date is a calendar day. A CarbonInterface may still carry a
     * time of day; 15:00 on the 30th must not build nights from a
     * different midnight.
     */
    public function test_the_date_is_the_calendar_day_not_the_time_of_day(): void
    {
        $shift = $this->hospitalNight();

        $presence = Presence::of(
            $shift,
            $shift,
            '22:00',
            CarbonImmutable::parse('2026-09-30 15:00:00'),
        );

        $this->assertFigures($presence, 480, 0, 480, 0);
    }

    /**
     * Eloquent dates are mutable Carbon. startOfDay() on those would
     * rewind the caller's instance, so the class must copy first.
     */
    public function test_a_mutable_date_is_not_rewound_to_midnight(): void
    {
        $date = Carbon::parse('2026-09-30 15:00:00');
        $shift = $this->hospitalNight();

        $this->assertFigures(Presence::of($shift, $shift, '22:00', $date), 480, 0, 480, 0);
        $this->assertSame('2026-09-30 15:00:00', $date->format('Y-m-d H:i:s'));
    }

    /**
     * Times are naive local wall clock. Converting to UTC first would
     * move 22:00 in Asia/Manila onto 14:00 UTC and out of a `'22:00'`
     * window built on UTC midnight.
     */
    public function test_night_does_not_convert_a_timezone(): void
    {
        $date = CarbonImmutable::parse('2026-09-30', 'Asia/Manila');
        $shift = [[
            CarbonImmutable::parse('2026-09-30 22:00:00', 'Asia/Manila'),
            CarbonImmutable::parse('2026-10-01 06:00:00', 'Asia/Manila'),
        ]];

        $presence = Presence::of($shift, $shift, '22:00', $date);

        $this->assertFigures($presence, 480, 0, 480, 0);
    }
}

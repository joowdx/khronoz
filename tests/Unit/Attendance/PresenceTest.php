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

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> */
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

    public function test_the_hospital_night_shift_is_entirely_night(): void
    {
        $shift = $this->hospitalNight();

        $presence = Presence::of($shift, $shift, '22:00', $this->date());

        $this->assertFigures($presence, 480, 0, 480, 0);
    }

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

    /** @return array<string, array{0: string, 1: int}> */
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

    public function test_a_mutable_date_is_not_rewound_to_midnight(): void
    {
        $date = Carbon::parse('2026-09-30 15:00:00');
        $shift = $this->hospitalNight();

        $this->assertFigures(Presence::of($shift, $shift, '22:00', $date), 480, 0, 480, 0);
        $this->assertSame('2026-09-30 15:00:00', $date->format('Y-m-d H:i:s'));
    }

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

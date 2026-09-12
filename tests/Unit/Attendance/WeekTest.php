<?php

namespace Tests\Unit\Attendance;

use App\Attendance\Week;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WeekTest extends TestCase
{
    private const CEILING = 48 * 60;

    /**
     * @return array{worked: int, credited: int, excess: int}
     */
    private static function day(int $worked, int $credited = 0, int $excess = 0): array
    {
        return ['worked' => $worked, 'credited' => $credited, 'excess' => $excess];
    }

    /** @return array<string, array{0: list<array{worked: int, credited: int, excess: int}>, 1: ?int, 2: int, 3: int, 4: int, 5: int}> */
    public static function weeks(): array
    {
        $twelve = self::day(12 * 60);
        $eight = self::day(8 * 60);
        $thirteen = self::day(13 * 60, 0, 60);

        return [
            'no ceiling' => [
                [$eight, $eight, $eight, $eight, self::day(8 * 60, 0, 60)],
                null,
                5 * 8 * 60,
                60,
                0,
                60,
            ],
            'under the ceiling' => [
                [$eight, $eight, $eight, $eight, self::day(0, 8 * 60)],
                self::CEILING,
                5 * 8 * 60,
                0,
                0,
                0,
            ],
            'over it with no daily excess' => [
                [$twelve, $twelve, $twelve, $twelve, $eight],
                self::CEILING,
                (4 * 12 + 8) * 60,
                0,
                8 * 60,
                8 * 60,
            ],
            'over it with daily excess that alone exceeds the overage' => [
                [self::day(10 * 60), self::day(10 * 60), self::day(10 * 60), self::day(10 * 60), self::day(8 * 60 + 20, 0, 3 * 60)],
                self::CEILING,
                48 * 60 + 20,
                3 * 60,
                0,
                3 * 60,
            ],
            'over it with daily excess smaller than the overage' => [
                [$twelve, $twelve, $twelve, $twelve, self::day(8 * 60, 0, 60)],
                self::CEILING,
                (4 * 12 + 8) * 60,
                60,
                7 * 60,
                8 * 60,
            ],
            'an empty week' => [
                [],
                null,
                0,
                0,
                0,
                0,
            ],
            'a single 13-hour day' => [
                [$thirteen],
                self::CEILING,
                13 * 60,
                60,
                0,
                60,
            ],
        ];
    }

    /**
     * @param  list<array{worked: int, credited: int, excess: int}>  $workdays
     */
    #[DataProvider('weeks')]
    public function test_overtime_equals_the_maximum_of_daily_excess_and_weekly_overage(
        array $workdays,
        ?int $ceiling,
        int $total,
        int $dailyExcess,
        int $weeklyOnly,
        int $overtime,
    ): void {
        $week = Week::of($workdays, $ceiling);

        $this->assertSame($total, $week->total());
        $this->assertSame($dailyExcess, $week->dailyExcess());
        $this->assertSame($weeklyOnly, $week->weeklyOnly());
        $this->assertSame($overtime, $week->overtime());
        $this->assertSame($dailyExcess + $weeklyOnly, $week->overtime());

        if ($ceiling === null) {
            $this->assertSame($dailyExcess, $week->overtime());

            return;
        }

        $this->assertSame(
            max($dailyExcess, $total - $ceiling),
            $week->overtime(),
        );
    }

    public function test_a_null_ceiling_is_not_a_ceiling_of_zero(): void
    {
        $days = [self::day(8 * 60)];

        $unbound = Week::of($days, null);

        $this->assertSame(0, $unbound->weeklyOnly());
        $this->assertSame(0, $unbound->overtime());

        $zero = Week::of($days, 0);

        $this->assertSame(8 * 60, $zero->weeklyOnly());
        $this->assertSame(8 * 60, $zero->overtime());
    }

    public function test_bounds_put_a_sunday_in_the_iso_week_that_ends_on_it(): void
    {
        $sunday = CarbonImmutable::parse('2026-01-04');

        [$monday, $end] = Week::bounds($sunday);

        $this->assertInstanceOf(CarbonImmutable::class, $monday);
        $this->assertInstanceOf(CarbonImmutable::class, $end);
        $this->assertSame('2025-12-29', $monday->toDateString());
        $this->assertSame('2026-01-04', $end->toDateString());
        $this->assertTrue($monday->isStartOfDay());
        $this->assertTrue($end->isStartOfDay());
    }

    public function test_bounds_of_any_day_are_that_iso_week(): void
    {
        $thursday = CarbonImmutable::parse('2026-01-01 15:30:00');

        [$monday, $sunday] = Week::bounds($thursday);

        $this->assertSame('2025-12-29', $monday->toDateString());
        $this->assertSame('2026-01-04', $sunday->toDateString());
        $this->assertTrue($monday->isStartOfDay());
        $this->assertTrue($sunday->isStartOfDay());
    }

    public function test_bounds_do_not_convert_a_timezone(): void
    {
        $date = CarbonImmutable::parse('2025-12-29 00:30:00', 'Asia/Manila');

        [$monday, $sunday] = Week::bounds($date);

        $this->assertSame('Asia/Manila', $monday->timezoneName);
        $this->assertSame('Asia/Manila', $sunday->timezoneName);
        $this->assertSame('2025-12-29', $monday->toDateString());
        $this->assertSame('2026-01-04', $sunday->toDateString());
    }
}

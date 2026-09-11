<?php

namespace Tests\Unit\Attendance;

use App\Attendance\Week;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Decision 52: the week's overtime is a maximum, not a sum.
 *
 * DA 02-04 makes work beyond twelve hours a day *or* forty-eight a week
 * overtime, and *or* is a maximum. Adding the two ceilings charges a
 * 13-hour Tuesday twice — once as the hour past the daily threshold and
 * again as an hour past 48. The identity this test pins is
 * `dailyExcess + max(0, total − ceiling − dailyExcess)
 * ≡ max(dailyExcess, total − ceiling)`, and it is what stops that
 * double count.
 *
 * A unit test and not a feature test: Week takes already-loaded figures
 * as plain arrays, so the arithmetic is exercised without a database.
 */
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

    /**
     * @return array<string, array{0: list<array{worked: int, credited: int, excess: int}>, 1: ?int, 2: int, 3: int, 4: int, 5: int}>
     */
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
     * Each case asserts the four accessors against known values, then that
     * overtime() equals the maximum form. The two must stay in lockstep:
     * a sum of the ceilings would pass the accessor checks only if the
     * expected overtime were also written as a sum, but it would fail the
     * identity the moment daily excess and weekly overage overlap.
     *
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

    /**
     * Null means the rule does not bind (the civil-service case). A ceiling
     * of zero would make every worked minute overtime. Guard the null before
     * the arithmetic, not inside it — `total − null` is `total − 0` in PHP.
     */
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

    /**
     * Sunday is the day that moves under a Sunday-first convention. 4 January
     * 2026 is a Sunday, and it is also the day an ISO week straddles a month
     * end — Monday 29 December 2025 through Sunday 4 January 2026 — which is
     * why bounds() exists instead of reading one ledger.
     */
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

    /**
     * A Thursday in the same ISO week must not start a different range.
     * 1 January 2026 is the month-end straddle from the other side.
     */
    public function test_bounds_of_any_day_are_that_iso_week(): void
    {
        $thursday = CarbonImmutable::parse('2026-01-01 15:30:00');

        [$monday, $sunday] = Week::bounds($thursday);

        $this->assertSame('2025-12-29', $monday->toDateString());
        $this->assertSame('2026-01-04', $sunday->toDateString());
        $this->assertTrue($monday->isStartOfDay());
        $this->assertTrue($sunday->isStartOfDay());
    }

    /**
     * Times are naive local wall clock. Converting to UTC first would move
     * a Monday 00:30 in Asia/Manila onto Sunday 16:30 UTC and into the
     * previous ISO week.
     */
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

<?php

namespace Tests\Unit\Attendance;

use App\Attendance\Expectation;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Expected slot sides for a date, against the worked shifts of
 * 04-scheduling.md. A unit test and not a feature test: the rule takes
 * the shift as a plain array precisely so those examples can run without
 * a database.
 *
 * 8 September 2026 is the Night workday the hospital example names: it
 * owns the 06:00 timelog of the 9th.
 */
class ExpectationTest extends TestCase
{
    private function day(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-08');
    }

    /**
     * @param  array{in: string, out: string, grace?: int, window: array{0: int, 1: int}}  $slot
     * @return array{slots: list<array{in: string, out: string, grace?: int, window: array{0: int, 1: int}}>}
     */
    private function shift(array ...$slots): array
    {
        return ['slots' => array_values($slots)];
    }

    /**
     * @param  array{slot: int, kind: string, at: mixed, grace: int, window: array{0: int, 1: int}}  $side
     * @param  array{0: int, 1: int}  $window
     */
    private function assertSide(array $side, int $slot, string $kind, string $at, int $grace, array $window): void
    {
        $this->assertSame($slot, $side['slot']);
        $this->assertSame($kind, $side['kind']);
        $this->assertInstanceOf(CarbonImmutable::class, $side['at']);
        $this->assertSame($at, $side['at']->format('Y-m-d H:i:s'));
        $this->assertSame($grace, $side['grace']);
        $this->assertSame($window, $side['window']);
    }

    public function test_standard_eight_to_five(): void
    {
        $morning = [-240, 180];
        $afternoon = [-120, 300];
        $shift = $this->shift(
            ['in' => '08:00', 'out' => '12:00', 'window' => $morning],
            ['in' => '13:00', 'out' => '17:00', 'window' => $afternoon],
        );

        $sides = Expectation::sides($shift, $this->day());

        $this->assertCount(4, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 08:00:00', 0, $morning);
        $this->assertSide($sides[1], 1, 'out', '2026-09-08 12:00:00', 0, $morning);
        $this->assertSide($sides[2], 2, 'in', '2026-09-08 13:00:00', 0, $afternoon);
        $this->assertSide($sides[3], 2, 'out', '2026-09-08 17:00:00', 0, $afternoon);
    }

    public function test_flexi_with_flex_one_hundred_and_eighty(): void
    {
        $morning = [-30, 240];
        $afternoon = [-60, 360];
        $shift = $this->shift(
            ['in' => '07:00', 'out' => '11:00', 'window' => $morning],
            ['in' => '12:00', 'out' => '16:00', 'window' => $afternoon],
        );

        $sides = Expectation::sides($shift, $this->day());

        $this->assertCount(4, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 07:00:00', 0, $morning);
        $this->assertSide($sides[1], 1, 'out', '2026-09-08 11:00:00', 0, $morning);
        $this->assertSide($sides[2], 2, 'in', '2026-09-08 12:00:00', 0, $afternoon);
        $this->assertSide($sides[3], 2, 'out', '2026-09-08 16:00:00', 0, $afternoon);

        $this->assertSame(83, Expectation::offset($sides, CarbonImmutable::parse('2026-09-08 08:23:00'), 180), 'arrive 08:23');
        $this->assertSame(180, Expectation::offset($sides, CarbonImmutable::parse('2026-09-08 10:30:00'), 180), 'arrive 10:30, capped');
        $this->assertSame(0, Expectation::offset($sides, CarbonImmutable::parse('2026-09-08 06:30:00'), 180), 'arrive 06:30, floored');

        $slid = Expectation::slid($sides, 83);

        $this->assertSide($slid[0], 1, 'in', '2026-09-08 08:23:00', 0, $morning);
        $this->assertSide($slid[1], 1, 'out', '2026-09-08 12:23:00', 0, $morning);
        $this->assertSide($slid[2], 2, 'in', '2026-09-08 13:23:00', 0, $afternoon);
        $this->assertSide($slid[3], 2, 'out', '2026-09-08 17:23:00', 0, $afternoon);
        $this->assertSame('2026-09-08 07:00:00', $sides[0]['at']->format('Y-m-d H:i:s'), 'slid must not mutate');

        $capped = Expectation::slid($sides, 180);

        $this->assertSide($capped[0], 1, 'in', '2026-09-08 10:00:00', 0, $morning);
        $this->assertSide($capped[1], 1, 'out', '2026-09-08 14:00:00', 0, $morning);
        $this->assertSide($capped[2], 2, 'in', '2026-09-08 15:00:00', 0, $afternoon);
        $this->assertSide($capped[3], 2, 'out', '2026-09-08 19:00:00', 0, $afternoon);
    }

    public function test_long_seven_to_six_compressed_work_week(): void
    {
        $morning = [-240, 180];
        $afternoon = [-120, 300];
        $shift = $this->shift(
            ['in' => '07:00', 'out' => '12:00', 'window' => $morning],
            ['in' => '13:00', 'out' => '18:00', 'window' => $afternoon],
        );

        $sides = Expectation::sides($shift, $this->day());

        $this->assertCount(4, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 07:00:00', 0, $morning);
        $this->assertSide($sides[1], 1, 'out', '2026-09-08 12:00:00', 0, $morning);
        $this->assertSide($sides[2], 2, 'in', '2026-09-08 13:00:00', 0, $afternoon);
        $this->assertSide($sides[3], 2, 'out', '2026-09-08 18:00:00', 0, $afternoon);
    }

    public function test_hospital_morning(): void
    {
        $window = [-120, 120];
        $sides = Expectation::sides(
            $this->shift(['in' => '06:00', 'out' => '14:00', 'window' => $window]),
            $this->day(),
        );

        $this->assertCount(2, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 06:00:00', 0, $window);
        $this->assertSide($sides[1], 1, 'out', '2026-09-08 14:00:00', 0, $window);
    }

    public function test_hospital_afternoon(): void
    {
        $window = [-120, 120];
        $sides = Expectation::sides(
            $this->shift(['in' => '14:00', 'out' => '22:00', 'window' => $window]),
            $this->day(),
        );

        $this->assertCount(2, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 14:00:00', 0, $window);
        $this->assertSide($sides[1], 1, 'out', '2026-09-08 22:00:00', 0, $window);
    }

    public function test_hospital_night_twenty_two_to_thirty(): void
    {
        $window = [-120, 120];
        $sides = Expectation::sides(
            $this->shift(['in' => '22:00', 'out' => '30:00', 'window' => $window]),
            $this->day(),
        );

        $this->assertCount(2, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 22:00:00', 0, $window);
        $this->assertSide($sides[1], 1, 'out', '2026-09-09 06:00:00', 0, $window);
    }

    public function test_day_twelve(): void
    {
        $window = [-120, 120];
        $sides = Expectation::sides(
            $this->shift(['in' => '06:00', 'out' => '18:00', 'window' => $window]),
            $this->day(),
        );

        $this->assertCount(2, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 06:00:00', 0, $window);
        $this->assertSide($sides[1], 1, 'out', '2026-09-08 18:00:00', 0, $window);
    }

    public function test_night_twelve(): void
    {
        $window = [-120, 120];
        $sides = Expectation::sides(
            $this->shift(['in' => '18:00', 'out' => '30:00', 'window' => $window]),
            $this->day(),
        );

        $this->assertCount(2, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 18:00:00', 0, $window);
        $this->assertSide($sides[1], 1, 'out', '2026-09-09 06:00:00', 0, $window);
    }

    public function test_duty_twenty_four(): void
    {
        $window = [-60, 60];
        $sides = Expectation::sides(
            $this->shift(['in' => '08:00', 'out' => '32:00', 'window' => $window]),
            $this->day(),
        );

        $this->assertCount(2, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 08:00:00', 0, $window);
        $this->assertSide($sides[1], 1, 'out', '2026-09-09 08:00:00', 0, $window);
    }

    public function test_forty_eight_hour_duty_out_at_fifty_six(): void
    {
        $window = [-60, 60];
        $sides = Expectation::sides(
            $this->shift(['in' => '08:00', 'out' => '56:00', 'window' => $window]),
            $this->day(),
        );

        $this->assertCount(2, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 08:00:00', 0, $window);
        $this->assertSide($sides[1], 1, 'out', '2026-09-10 08:00:00', 0, $window);
    }

    public function test_ramadan(): void
    {
        $window = [-240, 180];
        $sides = Expectation::sides(
            $this->shift(['in' => '07:30', 'out' => '15:30', 'window' => $window]),
            $this->day(),
        );

        $this->assertCount(2, $sides);
        $this->assertSide($sides[0], 1, 'in', '2026-09-08 07:30:00', 0, $window);
        $this->assertSide($sides[1], 1, 'out', '2026-09-08 15:30:00', 0, $window);
    }

    public function test_empty_slots_expect_nothing(): void
    {
        $this->assertSame([], Expectation::sides(['slots' => []], $this->day()));
        $this->assertSame(0, Expectation::offset([], CarbonImmutable::parse('2026-09-08 08:00:00'), 180));
    }

    public function test_grace_belongs_to_the_in_side_only(): void
    {
        $window = [-240, 180];
        $sides = Expectation::sides(
            $this->shift(['in' => '08:00', 'out' => '12:00', 'grace' => 15, 'window' => $window]),
            $this->day(),
        );

        $this->assertSide($sides[0], 1, 'in', '2026-09-08 08:00:00', 15, $window);
        $this->assertSide($sides[1], 1, 'out', '2026-09-08 12:00:00', 0, $window);
    }

    public function test_seconds_are_truncated_not_rounded(): void
    {
        $sides = Expectation::sides(
            $this->shift(['in' => '07:00', 'out' => '11:00', 'window' => [-30, 240]]),
            $this->day(),
        );

        $this->assertSame(83, Expectation::offset($sides, CarbonImmutable::parse('2026-09-08 08:23:30'), 180));
        $this->assertSame(83, Expectation::offset($sides, CarbonImmutable::parse('2026-09-08 08:23:59'), 180));
        $this->assertSame(84, Expectation::offset($sides, CarbonImmutable::parse('2026-09-08 08:24:00'), 180));

        // The floor is on the truncated minute too: 06:59:30 truncates to
        // 06:59, which is still before the band opens.
        $this->assertSame(0, Expectation::offset($sides, CarbonImmutable::parse('2026-09-08 06:59:30'), 180));
    }

    public function test_a_fixed_shift_does_not_slide(): void
    {
        $sides = Expectation::sides(
            $this->shift(['in' => '08:00', 'out' => '17:00', 'window' => [-240, 180]]),
            $this->day(),
        );

        $this->assertSame(0, Expectation::offset($sides, CarbonImmutable::parse('2026-09-08 08:23:00'), 0));
    }
}

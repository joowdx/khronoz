<?php

namespace Tests\Unit\Attendance;

use App\Attendance\Cycle;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Cycle position: (D − anchor) mod length, against the resolution step in
 * 04-scheduling.md. A unit test and not a feature test: the rule takes
 * dates as Carbon instances precisely so it can be exercised without a
 * roster row.
 *
 * 7 September 2026 is a Monday, the Team A hospital-rotation anchor.
 */
class CycleTest extends TestCase
{
    private function monday(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-07');
    }

    public function test_the_anchor_date_is_position_zero(): void
    {
        $anchor = $this->monday();

        $this->assertSame(0, Cycle::position($anchor, $anchor, 7));
        $this->assertSame(0, Cycle::position($anchor, $anchor, 21));
    }

    public function test_days_after_the_anchor_count_forward_and_wrap(): void
    {
        $anchor = $this->monday();

        $this->assertSame(3, Cycle::position(CarbonImmutable::parse('2026-09-10'), $anchor, 7));
        $this->assertSame(0, Cycle::position(CarbonImmutable::parse('2026-09-14'), $anchor, 7));
        $this->assertSame(1, Cycle::position(CarbonImmutable::parse('2026-09-15'), $anchor, 7));
        $this->assertSame(7, Cycle::position(CarbonImmutable::parse('2026-09-14'), $anchor, 21));
    }

    public function test_a_date_before_the_anchor_wraps_to_a_positive_position(): void
    {
        $anchor = $this->monday();

        $this->assertSame(6, Cycle::position(CarbonImmutable::parse('2026-09-06'), $anchor, 7));
        $this->assertSame(4, Cycle::position(CarbonImmutable::parse('2026-09-04'), $anchor, 7));
        $this->assertSame(4, Cycle::position(CarbonImmutable::parse('2026-08-28'), $anchor, 7));
    }

    public function test_position_uses_the_calendar_date_not_elapsed_hours(): void
    {
        $anchor = CarbonImmutable::parse('2026-09-07 23:00:00');
        $date = CarbonImmutable::parse('2026-09-08 01:00:00');

        $this->assertSame(1, Cycle::position($date, $anchor, 7));
    }

    public function test_a_mutable_date_is_not_rewound_to_midnight(): void
    {
        $date = Carbon::parse('2026-09-08 15:00:00');
        $anchor = Carbon::parse('2026-09-07 09:00:00');

        $this->assertSame(1, Cycle::position($date, $anchor, 7));
        $this->assertSame('2026-09-08 15:00:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-07 09:00:00', $anchor->format('Y-m-d H:i:s'));
    }

    public function test_a_zero_length_is_refused_rather_than_dividing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cycle::position($this->monday(), $this->monday(), 0);
    }
}

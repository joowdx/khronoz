<?php

namespace Tests\Unit\Support;

use App\Support\Intervals;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Set algebra over half-open time ranges `[start, end)`.
 *
 * A unit test and not a feature test: Intervals is pure, so the cases
 * that would double-count a missed lunch-out or an abutting noon
 * boundary can be written as ranges without a database.
 */
class IntervalsTest extends TestCase
{
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
     * @param  list<array{0: string, 1: string}>  $expected
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $actual
     */
    private function assertRanges(array $expected, array $actual): void
    {
        $this->assertSame(
            $expected,
            array_map(fn (array $range): array => [
                $range[0]->format('Y-m-d H:i:s'),
                $range[1]->format('Y-m-d H:i:s'),
            ], $actual),
        );
    }

    public function test_overlapping_ranges_merge_into_one(): void
    {
        $merged = Intervals::union([
            $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
            $this->span('2026-09-30 11:00:00', '2026-09-30 13:00:00'),
        ]);

        $this->assertRanges([
            ['2026-09-30 08:00:00', '2026-09-30 13:00:00'],
        ], $merged);
        $this->assertSame(300, Intervals::minutes($merged));
    }

    public function test_touching_ranges_join_at_the_shared_instant(): void
    {
        $merged = Intervals::union([
            $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
            $this->span('2026-09-30 12:00:00', '2026-09-30 17:00:00'),
        ]);

        $this->assertRanges([
            ['2026-09-30 08:00:00', '2026-09-30 17:00:00'],
        ], $merged);
        $this->assertSame(540, Intervals::minutes($merged));
    }

    public function test_disjoint_ranges_stay_separate_and_sorted(): void
    {
        $merged = Intervals::union([
            $this->span('2026-09-30 13:00:00', '2026-09-30 17:00:00'),
            $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
        ]);

        $this->assertRanges([
            ['2026-09-30 08:00:00', '2026-09-30 12:00:00'],
            ['2026-09-30 13:00:00', '2026-09-30 17:00:00'],
        ], $merged);
        $this->assertSame(480, Intervals::minutes($merged));
    }

    public function test_an_empty_list_is_the_empty_set(): void
    {
        $this->assertSame([], Intervals::union([]));
        $this->assertSame([], Intervals::intersect([], [$this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00')]));
        $this->assertSame([], Intervals::subtract([], [$this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00')]));
        $this->assertSame(0, Intervals::minutes([]));
    }

    public function test_intersect_is_the_overlapping_minutes(): void
    {
        $overlap = Intervals::intersect(
            [$this->span('2026-09-30 08:00:00', '2026-09-30 17:00:00')],
            [
                $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
                $this->span('2026-09-30 13:00:00', '2026-09-30 17:00:00'),
            ],
        );

        $this->assertRanges([
            ['2026-09-30 08:00:00', '2026-09-30 12:00:00'],
            ['2026-09-30 13:00:00', '2026-09-30 17:00:00'],
        ], $overlap);
        $this->assertSame(480, Intervals::minutes($overlap));
    }

    public function test_abutting_ranges_do_not_intersect(): void
    {
        $this->assertSame([], Intervals::intersect(
            [$this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00')],
            [$this->span('2026-09-30 12:00:00', '2026-09-30 17:00:00')],
        ));
    }

    public function test_subtract_keeps_only_minutes_outside_the_cut(): void
    {
        $leftover = Intervals::subtract(
            [$this->span('2026-09-30 08:00:00', '2026-09-30 17:00:00')],
            [
                $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
                $this->span('2026-09-30 13:00:00', '2026-09-30 17:00:00'),
            ],
        );

        $this->assertRanges([
            ['2026-09-30 12:00:00', '2026-09-30 13:00:00'],
        ], $leftover);
        $this->assertSame(60, Intervals::minutes($leftover));
    }

    public function test_subtract_of_a_covering_range_is_empty(): void
    {
        $this->assertSame([], Intervals::subtract(
            [$this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00')],
            [$this->span('2026-09-30 07:00:00', '2026-09-30 13:00:00')],
        ));
    }

    public function test_after_skips_the_gaps_when_counting(): void
    {
        $this->assertRanges([
            ['2026-09-30 17:00:00', '2026-09-30 20:00:00'],
        ], Intervals::after([
            $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
            $this->span('2026-09-30 13:00:00', '2026-09-30 20:00:00'),
        ], 480));
    }

    public function test_after_zero_is_the_whole_set(): void
    {
        $this->assertRanges([
            ['2026-09-30 08:00:00', '2026-09-30 12:00:00'],
        ], Intervals::after([$this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00')], 0));
    }

    public function test_after_more_than_the_set_holds_is_empty(): void
    {
        $this->assertSame([], Intervals::after(
            [$this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00')],
            300,
        ));
    }

    public function test_after_a_range_boundary_drops_that_range_whole(): void
    {
        $this->assertRanges([
            ['2026-09-30 13:00:00', '2026-09-30 17:00:00'],
        ], Intervals::after([
            $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
            $this->span('2026-09-30 13:00:00', '2026-09-30 17:00:00'),
        ], 240));
    }

    public function test_after_counts_overlapping_ranges_once(): void
    {
        $this->assertRanges([
            ['2026-09-30 09:00:00', '2026-09-30 11:00:00'],
        ], Intervals::after([
            $this->span('2026-09-30 08:00:00', '2026-09-30 10:00:00'),
            $this->span('2026-09-30 09:00:00', '2026-09-30 11:00:00'),
        ], 60));
    }

    public function test_minutes_of_overlapping_ranges_do_not_double_count(): void
    {
        $this->assertSame(180, Intervals::minutes([
            $this->span('2026-09-30 08:00:00', '2026-09-30 10:00:00'),
            $this->span('2026-09-30 09:00:00', '2026-09-30 11:00:00'),
        ]));
    }

    public function test_a_fractional_minute_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Intervals::minutes([
            $this->span('2026-09-30 08:00:00', '2026-09-30 08:00:30'),
        ]);
    }

    public function test_results_compose_as_normalised_unions(): void
    {
        $presence = [
            $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
            $this->span('2026-09-30 11:00:00', '2026-09-30 17:00:00'),
        ];
        $expected = [
            $this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00'),
            $this->span('2026-09-30 13:00:00', '2026-09-30 17:00:00'),
        ];

        $worked = Intervals::intersect($presence, $expected);
        $excess = Intervals::subtract($presence, $expected);

        $this->assertRanges([
            ['2026-09-30 08:00:00', '2026-09-30 12:00:00'],
            ['2026-09-30 13:00:00', '2026-09-30 17:00:00'],
        ], $worked);
        $this->assertRanges([
            ['2026-09-30 12:00:00', '2026-09-30 13:00:00'],
        ], $excess);
        $this->assertSame(
            Intervals::minutes(Intervals::union($presence)),
            Intervals::minutes($worked) + Intervals::minutes($excess),
        );
    }
}

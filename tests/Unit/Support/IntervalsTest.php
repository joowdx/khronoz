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

    /**
     * Two slots whose punches overlap — a missed lunch out, a device
     * double-read across noon — must become one range. Summing per slot
     * would count the overlap twice; the union cannot write that.
     */
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

    /**
     * Half-open `[start, end)`: a slot ending at 12:00 and one starting
     * at 12:00 are adjacent, not overlapping. Closed ranges would make
     * noon a one-minute overlap counted twice.
     */
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

    /** Disjoint ranges stay separate and come back sorted by start. */
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

    /**
     * The morning overlap of a standard day: 08:00–12:00 against
     * 08:00–17:00 is four hours, not nine.
     */
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

    /**
     * Abutting at 12:00 must not produce a one-minute intersection.
     * That is the half-open contract: `[08:00, 12:00) ∩ [12:00, 17:00) = ∅`.
     */
    public function test_abutting_ranges_do_not_intersect(): void
    {
        $this->assertSame([], Intervals::intersect(
            [$this->span('2026-09-30 08:00:00', '2026-09-30 12:00:00')],
            [$this->span('2026-09-30 12:00:00', '2026-09-30 17:00:00')],
        ));
    }

    /**
     * Presence through lunch against a two-slot expectation: the hour
     * between 12:00 and 13:00 is the leftover, not a second copy of noon.
     */
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

    /**
     * `minutes()` measures a set. Passing the same hour twice must not
     * report 120 — that is the double count union exists to make unwriteable.
     */
    public function test_minutes_of_overlapping_ranges_do_not_double_count(): void
    {
        $this->assertSame(180, Intervals::minutes([
            $this->span('2026-09-30 08:00:00', '2026-09-30 10:00:00'),
            $this->span('2026-09-30 09:00:00', '2026-09-30 11:00:00'),
        ]));
    }

    /**
     * Decision 60: whole minutes by truncation of instants, never by
     * rounding spans. A range that is not a whole number of minutes
     * means something upstream broke that contract.
     */
    public function test_a_fractional_minute_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Intervals::minutes([
            $this->span('2026-09-30 08:00:00', '2026-09-30 08:00:30'),
        ]);
    }

    /**
     * Every method returns a normalised union, so intersect then subtract
     * is the same set as doing the operations in one step.
     */
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

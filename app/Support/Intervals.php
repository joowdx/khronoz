<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Set algebra over half-open time ranges `[start, end)`.
 *
 * A slot ending at 12:00 and one starting at 12:00 are adjacent, not
 * overlapping, and their union is one range of the combined length.
 * Closed ranges would make noon a one-minute overlap counted twice or
 * subtracted twice depending on which method saw it first. Every caller
 * has to know this, which is why it lives in the docblock rather than
 * only in the tests.
 *
 * Every method that returns ranges returns a normalised union: merged,
 * sorted, non-overlapping, with touching ranges joined. Results therefore
 * compose. `minutes()` unions first too, so overlapping inputs cannot
 * double-count — `worked` is a measure of a set of minutes, not a sum
 * over slots (daily rule 5, decision 60).
 *
 * Lengths are whole minutes by truncation of instants, never by rounding
 * spans. A range that is not a whole number of minutes means something
 * upstream broke that contract.
 *
 * @phpstan-type Range array{0: CarbonImmutable, 1: CarbonImmutable}
 */
final class Intervals
{
    /**
     * @param  list<Range>  $ranges
     * @return list<Range>
     */
    public static function union(array $ranges): array
    {
        $ranges = self::dropEmpty($ranges);

        if ($ranges === []) {
            return [];
        }

        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        $current = $ranges[0];

        foreach (array_slice($ranges, 1) as $range) {
            if ($range[0]->lessThanOrEqualTo($current[1])) {
                $current[1] = $current[1]->max($range[1]);

                continue;
            }

            $merged[] = $current;
            $current = $range;
        }

        $merged[] = $current;

        return $merged;
    }

    /**
     * @param  list<Range>  $a
     * @param  list<Range>  $b
     * @return list<Range>
     */
    public static function intersect(array $a, array $b): array
    {
        $a = self::union($a);
        $b = self::union($b);
        $overlap = [];

        foreach ($a as [$aStart, $aEnd]) {
            foreach ($b as [$bStart, $bEnd]) {
                $start = $aStart->max($bStart);
                $end = $aEnd->min($bEnd);

                if ($start->lessThan($end)) {
                    $overlap[] = [$start, $end];
                }
            }
        }

        return self::union($overlap);
    }

    /**
     * Everything in `$a` that is not in `$b`.
     *
     * @param  list<Range>  $a
     * @param  list<Range>  $b
     * @return list<Range>
     */
    public static function subtract(array $a, array $b): array
    {
        $leftover = [];

        foreach (self::union($a) as $range) {
            $remaining = [$range];

            foreach (self::union($b) as [$cutStart, $cutEnd]) {
                $next = [];

                foreach ($remaining as [$start, $end]) {
                    if ($cutEnd->lessThanOrEqualTo($start) || $cutStart->greaterThanOrEqualTo($end)) {
                        $next[] = [$start, $end];

                        continue;
                    }

                    if ($cutStart->greaterThan($start)) {
                        $next[] = [$start, $cutStart];
                    }

                    if ($cutEnd->lessThan($end)) {
                        $next[] = [$cutEnd, $end];
                    }
                }

                $remaining = $next;
            }

            array_push($leftover, ...$remaining);
        }

        return self::union($leftover);
    }

    /**
     * @param  list<Range>  $ranges
     */
    public static function minutes(array $ranges): int
    {
        $total = 0;

        foreach (self::union($ranges) as [$start, $end]) {
            $seconds = $end->getTimestamp() - $start->getTimestamp();

            if ($seconds % 60 !== 0) {
                throw new InvalidArgumentException('Range length is not a whole number of minutes.');
            }

            $total += intdiv($seconds, 60);
        }

        return $total;
    }

    /**
     * @param  list<Range>  $ranges
     * @return list<Range>
     */
    private static function dropEmpty(array $ranges): array
    {
        $kept = [];

        foreach ($ranges as $range) {
            if ($range[0]->lessThan($range[1])) {
                $kept[] = $range;
            }
        }

        return $kept;
    }
}

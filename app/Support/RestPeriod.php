<?php

namespace App\Support;

final class RestPeriod
{
    /** Minutes in the rest period Art. 91 requires. */
    public const REQUIRED_MINUTES = 24 * 60;

    private const MINUTES_PER_DAY = 24 * 60;

    /**
     * @param  array<int, array{slots: array<int, array{in: string, out: string}>, remote?: bool}>  $turns
     */
    public static function satisfied(array $turns, ?int $after): bool
    {
        // Null is not a lenient default — it is the civil-service regime,
        // where Art. 91 does not bind at all (decision 32).
        if ($after === null) {
            return true;
        }

        $work = self::workIntervals($turns);

        // A cycle with no work at all owes no rest.
        if ($work === []) {
            return true;
        }

        return self::longestRun($work, count($turns)) <= $after;
    }

    /**
     * @param  array<int, array{position: int, from: int, to: int}>  $work
     */
    private static function longestRun(array $work, int $length): int
    {
        $cycle = $length * self::MINUTES_PER_DAY;
        $count = count($work);

        // Where the qualifying rests fall. Index i means "between work day i
        // and the one after it", wrapping at the end of the cycle.
        $rested = [];

        foreach ($work as $i => $day) {
            $next = $work[($i + 1) % $count];
            $gap = $next['from'] - $day['to'];

            // Across the seam the next start belongs to the following
            // repetition of the cycle.
            if ($i === $count - 1) {
                $gap += $cycle;
            }

            $rested[$i] = $gap >= self::REQUIRED_MINUTES;
        }

        // No rest anywhere in a cycle that contains work: the run is
        // unbounded, so report more than any $after can allow.
        if (! in_array(true, $rested, true)) {
            return PHP_INT_MAX;
        }

        // Walk the circle from just after a rest, so no run is cut in half by
        // the array's own start.
        $start = array_search(true, $rested, true) + 1;
        $longest = 0;
        $run = 0;

        for ($step = 0; $step < $count; $step++) {
            $i = ($start + $step) % $count;
            $run++;

            if ($rested[$i]) {
                $longest = max($longest, $run);
                $run = 0;
            }
        }

        return max($longest, $run);
    }

    /**
     * @param  array<int, array{slots: array<int, array{in: string, out: string}>, remote?: bool}>  $turns
     * @return array<int, array{position: int, from: int, to: int}>
     */
    private static function workIntervals(array $turns): array
    {
        $intervals = [];

        foreach ($turns as $position => $shift) {
            $offset = $position * self::MINUTES_PER_DAY;
            $slots = $shift['slots'] ?? [];

            if ($slots === []) {
                if ($shift['remote'] ?? false) {
                    $intervals[] = ['position' => $position, 'from' => $offset, 'to' => $offset + self::MINUTES_PER_DAY];
                }

                continue;
            }

            $intervals[] = [
                'position' => $position,
                'from' => $offset + Minutes::of($slots[0]['in']),
                'to' => $offset + Minutes::of($slots[count($slots) - 1]['out']),
            ];
        }

        return array_values($intervals);
    }
}

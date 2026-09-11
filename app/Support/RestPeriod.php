<?php

namespace App\Support;

/**
 * Art. 91: "a rest period of not less than twenty-four (24) consecutive hours
 * after every six (6) consecutive normal work days"
 * (docs/reference/dole-rules.md section A, and section I item 1).
 *
 * `settings.rest_day_after` is the N in that sentence, and it is the number of
 * work days allowed before the rest is owed — not a count of rest days. Null
 * means the rule does not bind, which is the civil-service case: 40 hours over
 * 5 days by rule, so the weekly rest day never applies (decision 32).
 *
 * **This measures elapsed continuous no-work hours, and never counts Off
 * turns**, which is the whole reason it is not a one-line check. An Off turn
 * does not imply 24 continuous hours once shifts cross midnight: a ten-hour
 * night turn ending 08:00 on the Off day has already eaten eight hours of it,
 * so a following turn that starts at 06:00 leaves 22 hours of rest while a
 * turn-counting check sees a full Off day and passes. Section I item 1 names
 * exactly that.
 *
 * The cycle is circular: position `length - 1` is followed by position 0 of
 * the next repetition, so the gap across the seam is measured like any other.
 * A schedule that only satisfies the rule by never repeating does not satisfy
 * it.
 */
final class RestPeriod
{
    /** Minutes in the rest period Art. 91 requires. */
    public const REQUIRED_MINUTES = 24 * 60;

    private const MINUTES_PER_DAY = 24 * 60;

    /**
     * Whether a cycle gives the required rest after every $after consecutive
     * work days.
     *
     * $turns is the cycle in position order: one entry per position from 0 to
     * length - 1, each the shift at that position. Deliberately plain arrays
     * rather than models, so the rule is testable against the worked examples
     * in 04-scheduling.md without touching the database.
     *
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
     * The longest run of consecutive work days uninterrupted by a qualifying
     * rest, measured around the cycle.
     *
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
     * Each work day as one absolute-minute interval within the cycle.
     *
     * First `in` to last `out`, not one interval per slot: the gap between two
     * slots of one day is a meal period, not a rest period, and treating it as
     * one would certify a split shift as compliant.
     *
     * A time past 24:00 rolls into following days like a transit timetable
     * ("30:00" is 06:00 the next day), so `to` may exceed the day it belongs
     * to — which is exactly the case a turn count cannot see.
     *
     * A `remote` turn has no slots but is still compensable work (OP MC 114),
     * so it is counted as occupying its whole day. That can only make this
     * stricter, never more permissive, which is the safe direction for a
     * compliance check: it cannot certify a schedule that in fact denies rest.
     *
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

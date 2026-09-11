<?php

namespace App\Attendance;

use App\Enums\PunchKind;
use App\Support\Minutes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The ordered expected slot sides of a shift on a date: each `in`/`out`
 * as an absolute instant, with times past 24:00 rolling into later days
 * (04-scheduling.md, Slot shape and Resolution step 4).
 *
 * `"30:00"` is 06:00 on `$date + 1` and `"56:00"` is 08:00 on `$date + 2`.
 * That is why `at` is `$date->startOfDay()->addMinutes(Minutes::of($time))`
 * and never `setTimeFromTimeString()`, which wraps at midnight and would
 * put a night shift's out on the wrong day — the single mistake the
 * overnight flag existed to paper over.
 *
 * Times are naive local wall clock, Asia/Manila, never UTC. `timelogs.time`
 * is stored that way (03-terminals.md) and the whole engine compares
 * against it, so this class must not convert a timezone.
 *
 * Deliberately a static class over plain arrays rather than models, so
 * the worked shifts in 04-scheduling.md can be tested without a database.
 */
final class Expectation
{
    /**
     * The expected sides for $date, in slot-then-kind order: slot 1 in,
     * slot 1 out, slot 2 in, slot 2 out. An empty `slots` array is an Off
     * or Remote shift and expects nothing.
     *
     * @param  array{slots?: list<array{in: string, out: string, grace?: int, window: array{0: int, 1: int}}>}  $shift
     * @return list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>
     */
    public static function sides(array $shift, CarbonInterface $date): array
    {
        $day = CarbonImmutable::instance($date)->startOfDay();
        $sides = [];

        foreach ($shift['slots'] ?? [] as $index => $slot) {
            $pair = $index + 1;
            $window = $slot['window'];

            $sides[] = [
                'slot' => $pair,
                'kind' => PunchKind::In->value,
                'at' => $day->addMinutes(Minutes::of($slot['in'])),
                'grace' => $slot['grace'] ?? 0,
                'window' => $window,
            ];

            // grace is lateness tolerance. There is no such thing as
            // leaving late, so every out side carries 0 rather than the
            // slot's value.
            $sides[] = [
                'slot' => $pair,
                'kind' => PunchKind::Out->value,
                'at' => $day->addMinutes(Minutes::of($slot['out'])),
                'grace' => 0,
                'window' => $window,
            ];
        }

        return $sides;
    }

    /**
     * Minutes to slide every expected side: the first in's lateness,
     * capped at $flex and floored at zero (decision 59).
     *
     * Flexitime grants an arrival band, not a free choice of workday.
     * Arriving at 06:30 against an 07:00 in gives 0 — a signed offset
     * would let an early arrival manufacture an early departure. Those
     * minutes are not lost; they are early presence and accrue as
     * `excess` like any other.
     *
     * Zero when $sides is empty or $flex is 0.
     *
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     */
    public static function offset(array $sides, CarbonInterface $firstIn, int $flex): int
    {
        if ($sides === [] || $flex === 0) {
            return 0;
        }

        // Whole minutes by truncating the instant, never by rounding the span
        // (decision 60). A device punch at 08:23:30 is 83 minutes past an
        // 07:00 in, not 84 — the DTR prints 08:23, and a figure the employee
        // cannot reconstruct from the document in their hand is the defect
        // this record exists to avoid. Expected instants are already whole
        // minutes by construction, so only the actual one is truncated; the
        // diff is then exact and round() only guards float representation.
        $expected = CarbonImmutable::instance($sides[0]['at']);
        $actual = CarbonImmutable::instance($firstIn)->startOfMinute();
        $delta = (int) round($expected->diffInMinutes($actual, false));

        return max(0, min($delta, $flex));
    }

    /**
     * The same sides with every `at` advanced by $minutes. `window` and
     * `grace` are unchanged: the band moves the expectation, not the
     * tolerance around it.
     *
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @return list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>
     */
    public static function slid(array $sides, int $minutes): array
    {
        $slid = [];

        foreach ($sides as $side) {
            $side['at'] = CarbonImmutable::instance($side['at'])->addMinutes($minutes);
            $slid[] = $side;
        }

        return $slid;
    }
}

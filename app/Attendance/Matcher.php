<?php

namespace App\Attendance;

use App\Enums\PunchKind;
use Carbon\CarbonImmutable;

/**
 * Pair each expected slot side with the timelog that filled it, or none
 * (04-scheduling.md Matching; 06-attendance.md Punch).
 *
 * Pure: plain arrays in, a Matching out, no database. The caller passes
 * only unvoided, unclaimed timelogs; this class assumes that and does
 * not re-check.
 *
 * Decision 67: each timelog competes for its nearest accepting side;
 * among competitors the nearest fills it; equidistant ins keep the
 * earlier and outs the later. A loser is unused and does not cascade.
 */
final class Matcher
{
    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  list<array{id: string, time: CarbonImmutable, state: int}>  $timelogs
     */
    public static function match(array $sides, array $timelogs, int $flex, bool $trust): Matching
    {
        if ($sides === []) {
            return new Matching([], []);
        }

        $sides = self::slide($sides, $timelogs, $flex);

        return new Matching($sides, self::punches($sides, self::fill($sides, $timelogs, $trust)));
    }

    /**
     * Slide every side by the first in's offset, computed against the
     * unslid slot 1 window. Sliding first would move the interval, so
     * the arrival that was supposed to define the offset may no longer
     * be inside it.
     *
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  list<array{id: string, time: CarbonImmutable, state: int}>  $timelogs
     * @return list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>
     */
    private static function slide(array $sides, array $timelogs, int $flex): array
    {
        if ($flex === 0) {
            return $sides;
        }

        $first = null;

        foreach ($timelogs as $timelog) {
            if (self::slotAccepts($sides, 1, $timelog['time'])) {
                $first = $timelog;
                break;
            }
        }

        $offset = $first === null
            ? 0
            : Expectation::offset($sides, $first['time'], $flex);

        return Expectation::slid($sides, $offset);
    }

    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  list<array{id: string, time: CarbonImmutable, state: int}>  $timelogs
     * @return array<int, ?array{id: string, time: CarbonImmutable, state: int}>
     */
    private static function fill(array $sides, array $timelogs, bool $trust): array
    {
        $competitors = array_fill(0, count($sides), []);

        foreach ($timelogs as $timelog) {
            $index = self::nearestSide($sides, $timelog, $trust);

            if ($index === null) {
                continue;
            }

            $competitors[$index][] = $timelog;
        }

        $filled = [];

        foreach ($sides as $index => $side) {
            $filled[$index] = self::winner($side, $competitors[$index]);
        }

        return $filled;
    }

    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  array{id: string, time: CarbonImmutable, state: int}  $timelog
     */
    private static function nearestSide(array $sides, array $timelog, bool $trust): ?int
    {
        $hint = $trust ? self::hintedKind($timelog['state']) : null;
        $time = CarbonImmutable::instance($timelog['time'])->startOfMinute();
        $best = null;
        $bestDistance = null;

        foreach ($sides as $index => $side) {
            if ($hint !== null && $side['kind'] !== $hint) {
                continue;
            }

            if (! self::slotAccepts($sides, $side['slot'], $time)) {
                continue;
            }

            $distance = abs(self::minutesBetween($side['at'], $time));

            // Equal distance keeps the earlier side: a punch at a slot's
            // midpoint is more plausibly a late arrival than an early
            // departure, and an unstated tie is answered differently by
            // two sort implementations.
            if ($best === null || $distance < $bestDistance) {
                $best = $index;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Among timelogs competing for one side the nearest fills it. Only
     * an exact tie falls back to kind: in keeps the earlier, out the
     * later (decision 67). Timelogs arrive in ascending time order, so
     * keeping the current winner on an in tie and replacing on an out
     * tie is that rule.
     *
     * @param  array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}  $side
     * @param  list<array{id: string, time: CarbonImmutable, state: int}>  $competitors
     * @return ?array{id: string, time: CarbonImmutable, state: int}
     */
    private static function winner(array $side, array $competitors): ?array
    {
        $best = null;
        $bestDistance = null;

        foreach ($competitors as $timelog) {
            $distance = abs(self::minutesBetween($side['at'], $timelog['time']));

            if ($best === null || $distance < $bestDistance) {
                $best = $timelog;
                $bestDistance = $distance;

                continue;
            }

            if ($distance === $bestDistance && $side['kind'] === PunchKind::Out->value) {
                $best = $timelog;
            }
        }

        return $best;
    }

    /**
     * Slot k accepts t when in_k + window[0] <= t <= out_k + window[1].
     * The window belongs to the slot, not the side — both sides share it.
     *
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     */
    private static function slotAccepts(array $sides, int $slot, CarbonImmutable $time): bool
    {
        $in = null;
        $out = null;
        $window = null;

        foreach ($sides as $side) {
            if ($side['slot'] !== $slot) {
                continue;
            }

            $window = $side['window'];

            if ($side['kind'] === PunchKind::In->value) {
                $in = $side['at'];
            }

            if ($side['kind'] === PunchKind::Out->value) {
                $out = $side['at'];
            }
        }

        if ($in === null || $out === null || $window === null) {
            return false;
        }

        $actual = CarbonImmutable::instance($time)->startOfMinute();

        return $actual->gte($in->addMinutes($window[0]))
            && $actual->lte($out->addMinutes($window[1]));
    }

    /**
     * 0 check in, 3 break in, 4 overtime in → in. 1 check out, 2 break
     * out, 5 overtime out → out. Any other integer is no hint at all
     * (03-terminals.md rule 6) and must not be coerced into a kind.
     */
    private static function hintedKind(int $state): ?string
    {
        return match ($state) {
            0, 3, 4 => PunchKind::In->value,
            1, 2, 5 => PunchKind::Out->value,
            default => null,
        };
    }

    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  array<int, ?array{id: string, time: CarbonImmutable, state: int}>  $filled
     * @return list<array{slot: int, kind: string, expected_at: CarbonImmutable, timelog_id: ?string, actual_at: ?CarbonImmutable, deviation: ?int}>
     */
    private static function punches(array $sides, array $filled): array
    {
        $punches = [];

        foreach ($sides as $index => $side) {
            $timelog = $filled[$index];
            $expected = CarbonImmutable::instance($side['at']);

            if ($timelog === null) {
                $punches[] = [
                    'slot' => $side['slot'],
                    'kind' => $side['kind'],
                    'expected_at' => $expected,
                    'timelog_id' => null,
                    'actual_at' => null,
                    'deviation' => null,
                ];

                continue;
            }

            $actual = CarbonImmutable::instance($timelog['time'])->startOfMinute();

            $punches[] = [
                'slot' => $side['slot'],
                'kind' => $side['kind'],
                'expected_at' => $expected,
                'timelog_id' => $timelog['id'],
                'actual_at' => $actual,
                'deviation' => self::minutesBetween($expected, $actual),
            ];
        }

        return $punches;
    }

    /**
     * Whole signed minutes, actual minus expected, after truncating the
     * actual to the minute (decision 60). Expected instants are already
     * whole minutes, so the subtraction is exact; round() only guards
     * float representation.
     */
    private static function minutesBetween(CarbonImmutable $expected, CarbonImmutable $actual): int
    {
        $actual = CarbonImmutable::instance($actual)->startOfMinute();

        return (int) round($expected->diffInMinutes($actual, false));
    }
}

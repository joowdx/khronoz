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
 *
 * Decision 78: a day whose expectation is empty still records what the
 * device saw. There are no sides to compete for, so the taps pair off
 * in time order instead — see transits().
 */
final class Matcher
{
    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  list<array{id: string, time: CarbonImmutable, state: int}>  $timelogs
     * @param  CarbonImmutable  $date  The workday. Only `transits()` reads it —
     *                                 sides carry their own day — and it is
     *                                 required rather than optional because
     *                                 the case that needs it is the one a
     *                                 caller forgets (decision 87).
     */
    public static function match(array $sides, array $timelogs, int $flex, bool $trust, CarbonImmutable $date): Matching
    {
        if ($sides === []) {
            return new Matching([], self::transits($date, $timelogs));
        }

        $sides = self::slide($sides, $timelogs, $flex);

        return new Matching($sides, self::punches($sides, self::fill($sides, $timelogs, $trust)));
    }

    /**
     * The taps of a day with no expectation, paired off in time order:
     * first and second are slot 1's in and out, third and fourth slot 2's,
     * and a trailing odd tap is a slot with an in and no out
     * (decision 78).
     *
     * A rest day, a non-working holiday and a whole-day suspension all
     * arrive here, and daily rule 10 needs their minutes: the first 480 of
     * actual attendance are `credited` and the rest is `excess`. Before
     * this the matcher returned nothing for them and a full day of holiday
     * duty recorded zero of everything.
     *
     * **Alternation, and deliberately not the device's `state` hint even
     * where the shift says `trust`.** The hint decides *which side* a tap
     * is nearest to when sides exist; with none, the order already answers
     * the same question and a hint disagreeing with it would need a
     * conflict rule no document supplies. The cost is a double tap: 08:00,
     * 08:01 then 17:00 pairs as one minute worked and a trailing arrival
     * rather than nine hours. That errs the way decision 67 errs — an
     * ambiguous record must not become an entitlement — and the correction
     * path is rule 4's, a manual timelog or an exemption.
     *
     * `expected_at` and `deviation` are null throughout: there was no
     * expectation, and a zero deviation would claim a punctuality nobody
     * measured.
     *
     * **Bounded to `$date`'s own taps, and that bound is the whole of the
     * correctness here** (decision 87). Every other day is bounded by its
     * sides — `fill()` will not attach a tap outside a slot's window — and a
     * day with no sides had no bound at all. `Computer` loads the candidate
     * timelogs for the *whole range* once, so an expectation-free day inside
     * a three-month recompute paired off every unclaimed tap left in the
     * quarter: one arrival in June, one departure in August, and `excess`
     * overflowed the column. A calendar day is the only defensible bound
     * once there is no expectation to measure against, and it errs the way
     * decision 67 and this method already err — a night worked across
     * midnight on a rest day splits into two lone taps and measures nothing,
     * rather than becoming an entitlement out of an ambiguous record.
     *
     * @param  list<array{id: string, time: CarbonImmutable, state: int}>  $timelogs
     * @return list<array{slot: int, kind: string, expected_at: ?CarbonImmutable, timelog_id: ?string, actual_at: ?CarbonImmutable, deviation: ?int}>
     */
    private static function transits(CarbonImmutable $date, array $timelogs): array
    {
        $day = $date->toDateString();
        $own = array_filter(
            $timelogs,
            fn (array $timelog): bool => CarbonImmutable::instance($timelog['time'])->toDateString() === $day,
        );

        $punches = [];

        foreach (array_values($own) as $index => $timelog) {
            $punches[] = [
                'slot' => intdiv($index, 2) + 1,
                'kind' => $index % 2 === 0 ? PunchKind::In->value : PunchKind::Out->value,
                'expected_at' => null,
                'timelog_id' => $timelog['id'],
                'actual_at' => CarbonImmutable::instance($timelog['time'])->startOfMinute(),
                'deviation' => null,
            ];
        }

        return $punches;
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
     * @return list<array{slot: int, kind: string, expected_at: ?CarbonImmutable, timelog_id: ?string, actual_at: ?CarbonImmutable, deviation: ?int}>
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

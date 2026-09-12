<?php

namespace App\Attendance;

use App\Enums\PunchKind;
use Carbon\CarbonImmutable;

final class Matcher
{
    /**
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     * @param  list<array{id: string, time: CarbonImmutable, state: int}>  $timelogs
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

            if ($best === null || $distance < $bestDistance) {
                $best = $index;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
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

    private static function minutesBetween(CarbonImmutable $expected, CarbonImmutable $actual): int
    {
        $actual = CarbonImmutable::instance($actual)->startOfMinute();

        return (int) round($expected->diffInMinutes($actual, false));
    }
}

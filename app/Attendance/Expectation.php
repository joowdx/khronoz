<?php

namespace App\Attendance;

use App\Enums\PunchKind;
use App\Support\Minutes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class Expectation
{
    /**
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
     * @param  list<array{slot: int, kind: string, at: CarbonImmutable, grace: int, window: array{0: int, 1: int}}>  $sides
     */
    public static function offset(array $sides, CarbonInterface $firstIn, int $flex): int
    {
        if ($sides === [] || $flex === 0) {
            return 0;
        }

        $expected = CarbonImmutable::instance($sides[0]['at']);
        $actual = CarbonImmutable::instance($firstIn)->startOfMinute();
        $delta = (int) round($expected->diffInMinutes($actual, false));

        return max(0, min($delta, $flex));
    }

    /**
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

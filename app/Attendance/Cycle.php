<?php

namespace App\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class Cycle
{
    public static function position(CarbonInterface $date, CarbonInterface $anchor, int $length): int
    {

        if ($length === 0) {
            throw new InvalidArgumentException('Cycle length cannot be zero.');
        }

        $from = CarbonImmutable::parse($anchor->format('Y-m-d'));
        $to = CarbonImmutable::parse($date->format('Y-m-d'));
        $diff = (int) round($from->diffInDays($to, false));

        return (($diff % $length) + $length) % $length;
    }
}

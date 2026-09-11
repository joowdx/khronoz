<?php

namespace App\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Roster position of a date: (D − anchor) mod length
 * (04-scheduling.md, Resolution for employee E on date D).
 *
 * An anchor is cycle day zero, not a start date. `rosters.starts` is a
 * separate column and may precede it, so a date before the anchor is
 * ordinary — the hospital teams sitting seven positions apart are the
 * same schedule with three different anchors, and an employee whose
 * roster began last month still wraps around this week's zero.
 *
 * PHP's `%` is remainder, not modulo: `(-3) % 7` is `-3`, not `4`. The
 * formula here is `(($diff % $length) + $length) % $length` so a date
 * before the anchor still lands in `0 .. length - 1`.
 *
 * Deliberately a static class over Carbon dates rather than models, so
 * the arithmetic is testable without a roster row.
 */
final class Cycle
{
    public static function position(CarbonInterface $date, CarbonInterface $anchor, int $length): int
    {
        // schedules_length_bounded makes 1..366 the only legal values from
        // the database, but this class is pure and takes an int. Zero must
        // not reach `%`, which would throw DivisionByZeroError three frames
        // deep instead of a typed refusal.
        if ($length === 0) {
            throw new InvalidArgumentException('Cycle length cannot be zero.');
        }

        // Calendar dates, not elapsed hours: a CarbonInterface may carry a
        // time of day, and Eloquent's Carbon is mutable, so startOfDay() on
        // the argument itself would rewind the caller. Formatting to Y-m-d
        // also keeps the two dates in one timezone so Carbon 3 will not
        // convert them to UTC before subtracting (times here are naive
        // local wall clock).
        $from = CarbonImmutable::parse($anchor->format('Y-m-d'));
        $to = CarbonImmutable::parse($date->format('Y-m-d'));
        $diff = (int) round($from->diffInDays($to, false));

        return (($diff % $length) + $length) % $length;
    }
}

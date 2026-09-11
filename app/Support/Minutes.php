<?php

namespace App\Support;

/**
 * `'HH:MM'` to minutes past midnight, where the hour may exceed 24 and roll
 * into later days (04-scheduling.md, slot shape). `"30:00"` is 06:00 the next
 * day (1800) and `"56:00"` is 08:00 two days on (3360); a clock library that
 * wraps at 24 cannot say that, which is why the conversion is arithmetic
 * rather than a `DateTime`. Cap 72:00 lives on the slot CHECK, not here —
 * this helper only converts, so RestPeriod and the deriver share one
 * reading of a time the shape already accepted.
 */
final class Minutes
{
    public static function of(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}

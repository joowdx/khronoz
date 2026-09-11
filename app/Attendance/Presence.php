<?php

namespace App\Attendance;

use App\Support\Intervals;
use App\Support\Minutes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Daily rule 5 as set algebra over one day's presence and expectation.
 *
 * The four figures are measures of sets of minutes, never sums over
 * slots — two presence ranges that overlap, or expected pairs that abut
 * at 12:00, cannot double-count. Policy (what `worked` becomes on a
 * holiday, whether `assume` credits a half-filled slot, whether a
 * `travel` exemption zeroes excess) is the deriver's and is not here.
 *
 *     worked      = |presence ∩ expected|
 *     excess      = |presence \ expected|
 *     night       = |presence ∩ expected ∩ nightly|
 *     nightExcess = |(presence ∩ nightly) \ expected|
 *
 * `night` and `nightExcess` partition `|presence ∩ nightly|` by the
 * same boundary that splits `worked` from `excess` (decision 53).
 * `nightExcess` is its own set operation, not `total night − night`.
 *
 * The nightly window is `[nightFrom, 06:00)` recurring every calendar
 * night — `'18:00'` under RA 11701, `'22:00'` under Labor Code Art. 86
 * (decision 33). Ranges are built for `$date − 1` through `$date + 3`
 * because a slot may run to `"72:00"` and a shift window can accept a
 * timelog 240 minutes early.
 *
 * Times are naive local wall clock, Asia/Manila. The timezone on
 * `$date` is kept; converting it would move a 22:00 Manila instant
 * onto 14:00 UTC and out of a `'22:00'` window.
 *
 * @phpstan-type Range array{0: CarbonImmutable, 1: CarbonImmutable}
 */
final class Presence
{
    private function __construct(
        private int $worked,
        private int $excess,
        private int $night,
        private int $nightExcess,
    ) {}

    /**
     * @param  list<Range>  $presence
     * @param  list<Range>  $expected
     */
    public static function of(array $presence, array $expected, string $nightFrom, CarbonInterface $date): self
    {
        $presence = Intervals::union($presence);
        $expected = Intervals::union($expected);
        $nightly = self::nightly($nightFrom, $date);

        $worked = Intervals::intersect($presence, $expected);

        return new self(
            Intervals::minutes($worked),
            Intervals::minutes(Intervals::subtract($presence, $expected)),
            Intervals::minutes(Intervals::intersect($worked, $nightly)),
            Intervals::minutes(Intervals::subtract(Intervals::intersect($presence, $nightly), $expected)),
        );
    }

    public function worked(): int
    {
        return $this->worked;
    }

    public function excess(): int
    {
        return $this->excess;
    }

    public function night(): int
    {
        return $this->night;
    }

    public function nightExcess(): int
    {
        return $this->nightExcess;
    }

    /**
     * `[nightFrom, 06:00)` for each calendar night from `$date − 1`
     * through `$date + 3`.
     *
     * `$nightFrom` is a clock string. Never `setTimeFromTimeString()`,
     * which wraps at midnight and would put the start on the wrong
     * day — the same reason Expectation builds instants as
     * `startOfDay()` plus minutes. The end is the next day at 06:00.
     *
     * @return list<Range>
     */
    private static function nightly(string $nightFrom, CarbonInterface $date): array
    {
        $origin = CarbonImmutable::instance($date)->startOfDay();
        $from = Minutes::of($nightFrom);
        $ranges = [];

        for ($offset = -1; $offset <= 3; $offset++) {
            $day = $origin->addDays($offset);
            $ranges[] = [
                $day->addMinutes($from),
                $day->addDay()->addHours(6),
            ];
        }

        return Intervals::union($ranges);
    }
}

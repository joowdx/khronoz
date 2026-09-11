<?php

namespace App\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The ISO-week overtime accumulator of decision 52.
 *
 * DA 02-04 makes work beyond twelve hours a day *or* forty-eight a week
 * overtime, and *or* is a maximum, not an addition. Weekly-only overtime
 * is `max(0, total − ceiling − Σ daily excess)`; the week's overtime is
 * `Σ daily excess + weekly_only`, which is the identity
 *
 *     dailyExcess + max(0, total − ceiling − dailyExcess)
 *         ≡ max(dailyExcess, total − ceiling)
 *
 * Summing the two ceilings instead would charge a 13-hour Tuesday twice
 * — once as the hour past the daily threshold and again as an hour past
 * 48. That is the mistake this class exists to make unwriteable.
 *
 * Derived at read time over already-loaded workday figures, never stored:
 * a stored weekly total would be a cache of workdays that one recompute
 * puts out of date, which is the Timetable mistake
 * docs/reference/clockwork-audit.md records. Loaded by `bounds()`, never
 * from one ledger, because an ISO week straddles a month end.
 *
 * `total` is `Σ (worked + credited)`. `credited` is decision 51's
 * regular-hours-at-a-premium figure and it is time actually worked;
 * leaving it out would let a premium day's hours escape the weekly
 * count. Null `$ceiling` means the rule does not bind — the
 * civil-service case — so `weeklyOnly()` is 0 and overtime is exactly
 * `dailyExcess()`. A ceiling of zero would make the entire week
 * overtime; null is not a ceiling of zero.
 */
final class Week
{
    private function __construct(
        private int $total,
        private int $dailyExcess,
        private ?int $ceiling,
    ) {}

    /**
     * The ISO week containing `$date` as Monday and Sunday, both at
     * `startOfDay()`. Carbon's `startOfWeek()` honours a configurable
     * first day, so Monday is passed explicitly: a Sunday is the day
     * that moves under a Sunday-first convention, and an ISO week that
     * straddles a month end must never be taken from one ledger.
     *
     * Times are naive local wall clock. The timezone on `$date` is kept;
     * converting it would move a Monday morning in Asia/Manila onto the
     * previous Sunday in UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function bounds(CarbonInterface $date): array
    {
        $monday = CarbonImmutable::instance($date)->startOfWeek(CarbonInterface::MONDAY)->startOfDay();

        return [$monday, $monday->addDays(6)];
    }

    /**
     * @param  list<array{worked: int, credited: int, excess: int}>  $workdays
     */
    public static function of(array $workdays, ?int $ceiling): self
    {
        $total = 0;
        $dailyExcess = 0;

        foreach ($workdays as $workday) {
            $total += $workday['worked'] + $workday['credited'];
            $dailyExcess += $workday['excess'];
        }

        return new self($total, $dailyExcess, $ceiling);
    }

    public function total(): int
    {
        return $this->total;
    }

    public function dailyExcess(): int
    {
        return $this->dailyExcess;
    }

    public function weeklyOnly(): int
    {
        // Null is not a lenient default — it is the civil-service regime,
        // where DA 02-04's weekly ceiling does not bind at all (decision 52).
        if ($this->ceiling === null) {
            return 0;
        }

        return max(0, $this->total - $this->ceiling - $this->dailyExcess);
    }

    public function overtime(): int
    {
        return $this->dailyExcess() + $this->weeklyOnly();
    }
}

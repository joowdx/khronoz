<?php

namespace App\Attendance;

use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Holiday;
use App\Models\Suspension;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * For one employee over a range of dates, which holidays, work
 * suspensions and exemptions reach them on each date
 * (05-calendar.md).
 *
 * A loader and nothing else: it answers *what applies*, never *what that
 * does to the day*. Status, premium, truncation, excused-minute
 * arithmetic and `declared_at` prospectivity all belong to the next
 * chunk, which reads these rows whole.
 *
 * Three queries for the range — holidays, suspensions, exemptions — plus
 * one `appliesTo()->exists()` per suspension found. The readers filter
 * that already-loaded set in PHP, so asking many dates costs the same as
 * asking one. Holidays carry no extra agency filter: they are the one
 * table that reads two agencies, and a national row is owned by the
 * platform.
 */
final class Almanac
{
    /**
     * @param  Collection<int, Holiday>  $loadedHolidays
     * @param  Collection<int, Suspension>  $loadedSuspensions
     * @param  Collection<int, Exemption>  $loadedExemptions
     */
    private function __construct(
        private readonly Collection $loadedHolidays,
        private readonly Collection $loadedSuspensions,
        private readonly Collection $loadedExemptions,
    ) {}

    public static function for(Employee $employee, CarbonInterface $from, CarbonInterface $to): self
    {
        $start = CarbonImmutable::parse($from->format('Y-m-d'));
        $end = CarbonImmutable::parse($to->format('Y-m-d'));

        if ($start->gt($end)) {
            return new self(collect(), collect(), collect());
        }

        $holidays = Holiday::between($start, $end)->get();

        // appliesTo() reaches $this->workgroup->descendants() when a
        // workgroup is named, so the workgroup must be eager-loaded or
        // Model::shouldBeStrict() throws. Applicability is resolved here,
        // once per suspension found, rather than reimplemented or deferred
        // to the reader — the negative clause of rule 3 is too easy to drop.
        $suspensions = Suspension::between($start, $end)
            ->with('workgroup')
            ->get()
            ->filter(fn (Suspension $suspension): bool => $suspension->appliesTo()
                ->whereKey($employee->getKey())
                ->exists())
            ->values();

        // `overlapping`, not `between`, and the difference is real rather
        // than a naming accident: a holiday and a suspension each carry a
        // single `date` and are tested against a range, while an exemption
        // carries `[date, until]` and is a range tested against a range. A
        // 105-day maternity leave is one row whose `date` may sit months
        // before this window, so a `whereBetween('date', …)` loses it whole.
        $exemptions = Exemption::overlapping($start, $end)
            ->whereBelongsTo($employee)
            ->get();

        return new self($holidays, $suspensions, $exemptions);
    }

    /**
     * Holidays on $date. Plural on purpose: a local holiday and a national
     * one can share a date, and both are owed.
     *
     * @return Collection<int, Holiday>
     */
    public function holidays(CarbonInterface $date): Collection
    {
        $day = $date->format('Y-m-d');

        return $this->loadedHolidays
            ->filter(fn (Holiday $holiday): bool => $holiday->date->toDateString() === $day)
            ->values();
    }

    /**
     * Suspensions on $date that already reach this employee. Empty when
     * nothing applies — including a mother-office closure the person was
     * detailed out of.
     *
     * @return Collection<int, Suspension>
     */
    public function suspensions(CarbonInterface $date): Collection
    {
        $day = $date->format('Y-m-d');

        return $this->loadedSuspensions
            ->filter(fn (Suspension $suspension): bool => $suspension->date->toDateString() === $day)
            ->values();
    }

    /**
     * Exemptions whose closed [date, until] covers $date. A 105-day leave
     * is one row appearing under every day of it; `personal` is included,
     * because whether it excuses anything is Exemption::excused()'s job.
     *
     * @return Collection<int, Exemption>
     */
    public function exemptions(CarbonInterface $date): Collection
    {
        $day = $date->format('Y-m-d');

        return $this->loadedExemptions
            ->filter(fn (Exemption $exemption): bool => $exemption->date->toDateString() <= $day
                && $exemption->until->toDateString() >= $day)
            ->values();
    }
}

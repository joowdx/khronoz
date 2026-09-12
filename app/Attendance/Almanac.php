<?php

namespace App\Attendance;

use App\Models\Employee;
use App\Models\Exemption;
use App\Models\Holiday;
use App\Models\Suspension;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

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

        $suspensions = Suspension::between($start, $end)
            ->with('workgroup')
            ->get()
            ->filter(fn (Suspension $suspension): bool => $suspension->appliesTo()
                ->whereKey($employee->getKey())
                ->exists())
            ->values();

        $exemptions = Exemption::overlapping($start, $end)
            ->whereBelongsTo($employee)
            ->get();

        return new self($holidays, $suspensions, $exemptions);
    }

    /**
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

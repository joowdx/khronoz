<?php

namespace App\Jobs;

use App\Attendance\Almanac;
use App\Attendance\Calendar;
use App\Attendance\Computer;
use App\Attendance\Resolver;
use App\Attendance\Week;
use App\Enums\HolidayType;
use App\Models\Agency;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Scopes\AgencyScope;
use App\Support\Settings;
use App\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RecomputeWorkdays implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Mirrors `Computer::precedingUnexcusedAbsence`'s look-back. */
    private const REACH = 7;

    /**
     * @param public string $employeeId
     * @param public string $from
     * @param public string $to
     */
    public function __construct(
        public string $employeeId,
        public string $from,
        public string $to,
    ) {}

    /**
     * @param  iterable<int, array{employee_id: ?string, time: CarbonInterface|string}|object>  $pairs
     */
    public static function dispatchFor(iterable $pairs): void
    {
        $dates = [];

        foreach ($pairs as $pair) {
            $employeeId = is_array($pair) ? ($pair['employee_id'] ?? null) : ($pair->employee_id ?? null);
            $time = is_array($pair) ? ($pair['time'] ?? null) : ($pair->time ?? null);

            if ($employeeId === null || $time === null || $time === '') {
                continue;
            }

            $dates[$employeeId][CarbonImmutable::parse($time)->toDateString()] = true;
        }

        foreach ($dates as $employeeId => $employeeDates) {
            foreach (self::mergedIntervals(array_keys($employeeDates)) as [$from, $to]) {
                static::dispatch((string) $employeeId, $from, $to);
            }
        }
    }

    /**
     * @param  list<string>  $dates
     * @return list<array{0: string, 1: string}>
     */
    private static function mergedIntervals(array $dates): array
    {
        sort($dates);

        $merged = [];

        foreach ($dates as $date) {
            $end = CarbonImmutable::parse($date);
            $start = $end->subDays(3);

            if ($merged !== [] && $start->lte($merged[array_key_last($merged)][1]->addDay())) {
                $last = array_key_last($merged);

                if ($end->gt($merged[$last][1])) {
                    $merged[$last][1] = $end;
                }

                continue;
            }

            $merged[] = [$start, $end];
        }

        return array_map(
            fn (array $interval): array => [$interval[0]->toDateString(), $interval[1]->toDateString()],
            $merged,
        );
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->employeeId))->releaseAfter(60)->expireAfter(180)];
    }

    public function handle(Tenant $tenant): void
    {
        $employee = Employee::withoutGlobalScope(AgencyScope::class)->withTrashed()->findOrFail($this->employeeId);
        $agency = Agency::findOrFail($employee->agency_id);

        $tenant->within($agency, function () use ($employee, $agency): void {
            $from = CarbonImmutable::parse($this->from);
            $settings = new Settings($agency);
            $to = $this->reaching($employee, $settings, CarbonImmutable::parse($this->to));

            (new Computer($employee, $settings))->over($from, $to);
        });
    }

    private function reaching(Employee $employee, Settings $settings, CarbonImmutable $to): CarbonImmutable
    {
        $first = $to->addDay();
        $last = $to->addDays(self::REACH);

        [$weekStart] = Week::bounds($first);
        [, $weekEnd] = Week::bounds($last);

        $resolutions = (new Resolver($employee))->over($weekStart, $weekEnd);

        foreach ($resolutions as $resolution) {
            $resolution?->roster->schedule->loadMissing('fallbackShift');
        }

        $calendar = new Calendar($almanac = Almanac::for($employee, $weekStart, $weekEnd), $settings);

        for ($date = $first; $date->lte($last); $date = $date->addDay()) {
            if ($almanac->holidays($date)->contains(fn (Holiday $holiday): bool => $holiday->type === HolidayType::Regular)) {
                return $date;
            }

            if ($calendar->apply($resolutions, $date)->status?->expectsWork() ?? true) {
                return $to;
            }
        }

        return $to;
    }
}

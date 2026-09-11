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

/**
 * Recompute one employee's workdays over an inclusive date range.
 *
 * A queued job must set the tenant as its first scoped act (decision 61):
 * AgencyScope fails closed for HTTP and tests, but a queue worker is a
 * console process and reads every agency's rows if the tenant is unset.
 * Holiday::covering() is the query that would then mix in every tenant's
 * calendar, because holidays deliberately widen to the platform agency.
 *
 * Two jobs for one employee must never run concurrently ("Across midnight"
 * rule 1: the earlier workday claims a shared timelog first). That is
 * WithoutOverlapping with a releaseAfter, not ShouldBeUnique — uniqueness
 * would drop the second recompute while the first is still queued.
 *
 * The constructor takes the employee's id, not the model: a serialised
 * model re-resolves through the tenant scope on unqueue, which in a
 * worker is the unscoped read this job exists to prevent.
 */
class RecomputeWorkdays implements ShouldQueue
{
    use Queueable;

    /**
     * Not 1: `queue:work` defaults to `--tries=1`, and `Worker::process`
     * fails a job before running it when `attempts() > maxTries`.
     * `WithoutOverlapping::releaseAfter` puts the blocked job back on the
     * queue with `attempts()` incremented, so one try marks it failed on
     * the way back and never runs it — the same silent drop `ShouldBeUnique`
     * would cause. Five attempts wait out a long-running recompute for this
     * employee (`releaseAfter` 60s × 4 waits exceeds `expireAfter`) rather
     * than discarding it.
     */
    public int $tries = 5;

    /** Mirrors `Computer::precedingUnexcusedAbsence`'s look-back. */
    private const REACH = 7;

    public function __construct(
        public string $employeeId,
        public string $from,
        public string $to,
    ) {}

    /**
     * Queue a recompute for each employee named by the accepted pairs.
     *
     * An arriving or voided timelog at time T covers T::date − 3 … T::date
     * (the 72:00 slot cap). Those windows are merged when they overlap or
     * abut, so a month's consecutive punches stay one job, but a stray
     * year-2000 punch (dead RTC) is its own four-day job and does not
     * drag the span across the gap. A punch with no employee is skipped.
     *
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
     * Each date D is [D − 3, D]; merge while the next starts on or before
     * the day after the current one ends.
     *
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

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        // expireAfter 180, not the constructor's 0: Cache::lock($key, 0) never
        // expires, so a worker killed mid-job (deploy, OOM, SIGKILL) would
        // hold this employee forever. Computer::over() loads the range once
        // (roster, almanac, candidate timelogs) then, per day, a transaction
        // of firstOrCreate ledger, unclaimed-punch query, updateOrCreate
        // workday, punch delete and insert — on the order of half a dozen
        // statements. A merged month is ~35 days; a year of consecutive
        // punches is the widest legitimate span and still well under a
        // minute. Three minutes is a ceiling above that run, not a guess
        // at a single day.
        return [(new WithoutOverlapping($this->employeeId))->releaseAfter(60)->expireAfter(180)];
    }

    public function handle(Tenant $tenant): void
    {
        $employee = Employee::withoutGlobalScope(AgencyScope::class)->findOrFail($this->employeeId);
        $agency = Agency::findOrFail($employee->agency_id);

        $tenant->set($agency);

        $from = CarbonImmutable::parse($this->from);
        $settings = new Settings($agency);
        $to = $this->reaching($employee, $settings, CarbonImmutable::parse($this->to));

        (new Computer($employee, $settings))->over($from, $to);
    }

    /**
     * `$to`, extended forward to a following regular holiday whose credit
     * this range can still change (decisions 66 and 77).
     *
     * An unworked regular holiday credits `required` unless the preceding
     * **work** day was an unexcused absence, so a punch arriving for that
     * absence must recompute the holiday too. Decision 66 reached one day
     * forward, which is the whole distance only when the two are adjacent.
     * Decision 77 made the backward walk skip every day nothing was
     * required on, and this is the same walk run the other way: it steps
     * over rest days, non-working holidays and whole-day suspensions and
     * stops at the first day work was expected on, because that day — not
     * ours — is then the holiday's preceding work day and our range cannot
     * move it. A Monday regular holiday after a weekend is the ordinary
     * shape of this in the Philippines, and one day forward never reached
     * it.
     *
     * Seven days is `Computer::precedingUnexcusedAbsence`'s own bound, and
     * the two must agree: a distance the walk back would cross is a
     * distance the dispatch has to cover.
     */
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

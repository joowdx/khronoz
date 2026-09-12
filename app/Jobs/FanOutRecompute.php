<?php

namespace App\Jobs;

use App\Models\Scopes\AgencyScope;
use App\Models\Workday;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queue one `RecomputeWorkdays` per employee a **calendar** change reaches
 * (06-attendance.md Workday rule 3, decision 86).
 *
 * Rule 3's calendar events are not per-employee the way an arriving timelog
 * is: a suspension reaches a workgroup and its descendants, a holiday
 * reaches an agency, and a national holiday reaches every agency there is.
 * Fanning that out in the request that declared it would have a controller
 * dispatching thousands of jobs while somebody waits on a form, so the
 * fan-out is itself a job and the request queues exactly one.
 *
 * **It resolves its employees from `workdays`, not from `employees`** — and
 * that is the whole rule this class exists to hold. A calendar change
 * *refreshes days that were computed*; it never computes new ones. A
 * proclamation for Christmas filed in September otherwise writes a workday
 * dated 25 December for every employee in the country, three months of
 * absences ahead of the fact, on a DTR nobody has worked yet. Only a timelog
 * brings a day into existence (rule 3's first event, and the reason the span
 * of a recompute has ever been T−3…T); the calendar decides what an existing
 * day means. The clamp falls out of that: each employee's span is narrowed to
 * the first and last day they actually have inside the range, and an employee
 * with none is not dispatched at all. It also gets three things for free —
 * the unemployed are absent from `workdays` by decision 82, a locked month is
 * still skipped courteously downstream, and a removed employee's final month
 * is still reachable, because workdays do not soft-delete with the person.
 *
 * Scope is a *description* and never a list of ids on a large set:
 * `$employeeIds` for the small, knowable cases (one exemption, one
 * enrollment, one roster, a suspended workgroup), `$agencyId` for an
 * agency's own calendar, and neither for a platform-owned holiday — which is
 * the one case whose id list would not fit in a payload.
 *
 * Clears the tenant for the reason decision 84 gives and puts it back for
 * the reason decision 86 does: this job crosses agencies by design, and the
 * tenant each child job needs is its own, set by `RecomputeWorkdays` from
 * the employee it was given — but under `QUEUE_CONNECTION=sync` the caller
 * is a request that still has work to do with its own.
 */
class FanOutRecompute implements ShouldQueue
{
    use Queueable;

    /**
     * @param  ?string  $to  Null is open-ended: the caller knows where the
     *                       change starts and not where it stops, and the
     *                       last computed day is the real answer anyway.
     * @param  ?list<string>  $employeeIds  Exactly these people, or null to
     *                                      resolve them from `$agencyId`.
     */
    public function __construct(
        public string $from,
        public ?string $to = null,
        public ?string $agencyId = null,
        public ?array $employeeIds = null,
    ) {}

    /** @param iterable<int, string> $employeeIds */
    public static function forEmployees(iterable $employeeIds, string $from, ?string $to = null): void
    {
        $ids = array_values(array_unique(array_map('strval', [...$employeeIds])));

        if ($ids === []) {
            return;
        }

        static::dispatch($from, $to, null, $ids);
    }

    /** Everyone the agency has a computed day for over the span. */
    public static function forAgency(string $agencyId, string $from, ?string $to = null): void
    {
        static::dispatch($from, $to, $agencyId);
    }

    /** Every agency: a platform-owned holiday reaches all of them. */
    public static function forEveryAgency(string $from, ?string $to = null): void
    {
        static::dispatch($from, $to);
    }

    public function handle(Tenant $tenant): void
    {
        $tenant->within(null, function (): void {
            $this->fanOut();
        });
    }

    private function fanOut(): void
    {
        // Eloquent for the scope removal, the base builder for the aggregate:
        // one row per employee, and no model hydration for a query whose
        // columns are two dates and an id.
        $query = Workday::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->toBase()
            ->where('date', '>=', $this->from)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, min(date)::text as opens, max(date)::text as closes')
            ->orderBy('employee_id');

        if ($this->to !== null) {
            $query->where('date', '<=', $this->to);
        }

        if ($this->employeeIds !== null) {
            $query->whereIn('employee_id', $this->employeeIds);
        } elseif ($this->agencyId !== null) {
            $query->where('agency_id', $this->agencyId);
        }

        // cursor(), not get(): a platform holiday's set is every employee of
        // every agency and the point of this job is not to hold it.
        foreach ($query->cursor() as $row) {
            RecomputeWorkdays::dispatch($row->employee_id, $row->opens, $row->closes);
        }
    }
}

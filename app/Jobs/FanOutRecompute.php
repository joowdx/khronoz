<?php

namespace App\Jobs;

use App\Models\Scopes\AgencyScope;
use App\Models\Workday;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FanOutRecompute implements ShouldQueue
{
    use Queueable;

    /**
     * @param  ?string  $to  Null is open-ended: the caller knows where the
     * @param  ?list<string>  $employeeIds  Exactly these people, or null to
     * @param public string $from
     * @param public ?string $agencyId
     */
    public function __construct(
        public string $from,
        public ?string $to = null,
        public ?string $agencyId = null,
        public ?array $employeeIds = null,
    ) {}

    /**
     * @param  iterable<int, string>  $employeeIds
     */
    public static function forEmployees(iterable $employeeIds, string $from, ?string $to = null): void
    {
        $ids = array_values(array_unique(array_map('strval', [...$employeeIds])));

        if ($ids === []) {
            return;
        }

        static::dispatch($from, $to, null, $ids);
    }

    public static function forAgency(string $agencyId, string $from, ?string $to = null): void
    {
        static::dispatch($from, $to, $agencyId);
    }

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

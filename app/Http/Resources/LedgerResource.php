<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Ledger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Ledger` interface in resources/js/types/index.d.ts.
 *
 * Index queries add `withCount('workdays')` and `withSum` over worked, tardy
 * and undertime; those keys live on the page's own row type, not on `Ledger`,
 * and are exposed with `whenCounted` / `whenHas` so a query that did not ask
 * for them leaves the key absent rather than reading as zero. There is no
 * overtime column here: `Ledger::view()` is the only thing that can compute
 * it, and that belongs on the DTR page.
 *
 * `month` is a date-cast column, sent as `YYYY-MM-DD` (.ai/rules/resources.md).
 *
 * @mixin Ledger
 */
class LedgerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded(
                'employee',
                fn (Employee $employee) => EmployeeResource::make($employee)->resolve(),
            ),
            'month' => $this->month->toDateString(),
            'locked_at' => $this->locked_at?->toDateTimeString(),
            'workdays_count' => $this->whenCounted('workdays'),
            'worked' => $this->whenHas('worked', fn (mixed $value): int => (int) $value),
            'tardy' => $this->whenHas('tardy', fn (mixed $value): int => (int) $value),
            'undertime' => $this->whenHas('undertime', fn (mixed $value): int => (int) $value),
        ];
    }
}

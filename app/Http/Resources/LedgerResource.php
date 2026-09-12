<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Ledger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Ledger` interface in resources/js/types/index.d.ts.
 *
 * @mixin Ledger
 */
class LedgerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
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

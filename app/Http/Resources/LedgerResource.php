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
                fn (Employee $employee) => [...EmployeeResource::make($employee)->resolve(), ...($this->identity['employee'] ?? [])],
            ),
            'month' => $this->month->toDateString(),
            'starts' => $this->starts->toDateString(),
            'ends' => $this->ends->toDateString(),
            'scope' => ['value' => $this->scope->value, 'label' => $this->scope->label()],
            'revision' => $this->revision,
            'cadence_id' => $this->cadence_id,
            'cadence' => $this->whenLoaded('cadence', fn ($cadence) => CadenceResource::make($cadence)->resolve()),
            'locked_at' => $this->locked_at?->toDateTimeString(),
            'locked_by' => $this->locked_by,
            'unlocked_at' => $this->unlocked_at?->toDateTimeString(),
            'unlocked_by' => $this->unlocked_by,
            'identity' => $this->identity,
            'policy' => $this->policy,
            'signers' => $this->signers,
            'attestations' => $this->whenLoaded('attestations', fn ($attestations) => AttestationResource::collection($attestations)->resolve()),
            'renditions' => $this->whenLoaded('renditions', fn ($renditions) => RenditionResource::collection($renditions)->resolve()),
            'workdays_count' => $this->whenCounted('workdays', fn (mixed $value): int => isset($this->calculation['workdays']) ? count($this->calculation['workdays']) : (int) $value),
            'worked' => $this->whenHas('worked', fn (mixed $value): int => (int) ($this->calculation['totals']['worked'] ?? $value)),
            'tardy' => $this->whenHas('tardy', fn (mixed $value): int => (int) ($this->calculation['totals']['tardy'] ?? $value)),
            'undertime' => $this->whenHas('undertime', fn (mixed $value): int => (int) ($this->calculation['totals']['undertime'] ?? $value)),
        ];
    }
}

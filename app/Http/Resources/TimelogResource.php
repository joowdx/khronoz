<?php

namespace App\Http\Resources;

use App\Enums\AttlogMode;
use App\Enums\AttlogState;
use App\Models\Timelog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Timelog` interface in resources/js/types/index.d.ts.
 *
 * @mixin Timelog
 */
class TimelogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'terminal_id' => $this->terminal_id,
            'terminal' => $this->whenLoaded(
                'terminal',
                fn () => $this->terminal === null ? null : TerminalResource::make($this->terminal)->resolve(),
            ),
            'uid' => $this->uid,
            'time' => $this->time->toDateTimeString(),
            'state' => ['value' => $this->state, 'label' => AttlogState::describe($this->state)],
            'mode' => ['value' => $this->mode, 'label' => AttlogMode::describe($this->mode)],
            'source' => $this->source->value,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded(
                'employee',
                fn () => $this->employee === null ? null : EmployeeResource::make($this->employee)->resolve(),
            ),
            'voided_at' => $this->voided_at?->toDateTimeString(),
            'reason' => $this->reason,
            // strike-out of a pay record is attributable, and an attribution
            // nobody can read is not one. `{id, name}` rather than a
            // UserResource, matching SuspensionResource::user.
            'voider' => $this->whenLoaded(
                'voider',
                fn () => $this->voider === null ? null : ['id' => $this->voider->id, 'name' => $this->voider->name],
            ),
        ];
    }
}

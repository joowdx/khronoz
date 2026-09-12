<?php

namespace App\Http\Resources;

use App\Models\Sync;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Sync` interface in resources/js/types/index.d.ts.
 *
 * @mixin Sync
 */
class SyncResource extends JsonResource
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
            'trigger' => ['value' => $this->trigger->value, 'label' => $this->trigger->label()],
            'status' => $this->status->value,
            'started_at' => $this->started_at->toDateTimeString(),
            'finished_at' => $this->finished_at?->toDateTimeString(),
            'received' => $this->received,
            'accepted' => $this->accepted,
            'duplicates' => $this->duplicates,
            'rejected' => $this->rejected,
            'reference' => $this->reference,
            'earliest' => $this->earliest?->toDateTimeString(),
            'latest' => $this->latest?->toDateTimeString(),
            'error' => $this->error,
        ];
    }
}

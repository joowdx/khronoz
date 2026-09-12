<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches the `Attestation` interface in resources/js/types/index.d.ts.
 * @mixin \App\Models\Attestation
 */
class AttestationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'role' => $this->role, 'sequence' => $this->sequence,
            'user_id' => $this->user_id, 'name' => $this->name,
            'at' => $this->at->toDateTimeString(), 'withdrawn_at' => $this->withdrawn_at?->toDateTimeString(),
            'can_withdraw' => $request->user()?->can('withdraw', $this->resource) ?? false,
        ];
    }
}

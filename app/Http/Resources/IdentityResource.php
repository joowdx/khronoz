<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches the Identity interface in resources/js/types/index.d.ts. */
class IdentityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'provider' => $this->provider, 'email' => $this->email,
            'created_at' => $this->created_at->toIso8601String(), 'last_used_at' => $this->last_used_at?->toIso8601String()];
    }
}

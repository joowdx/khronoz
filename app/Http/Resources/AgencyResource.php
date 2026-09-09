<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Agency` interface in resources/js/types/index.d.ts.
 *
 * `users_count` is present only where the query asked for it — the agencies
 * list, which shows it as a column. Everywhere else (the shared `agency` and
 * `agencies` props, the edit page) the key is absent rather than zero, so a
 * missing count can never read as "no users". The list's own row type is
 * `AgencyRow` in resources/js/pages/platform/agencies/index.tsx.
 *
 * @mixin \App\Models\Agency
 */
class AgencyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'platform' => $this->platform,
            'users_count' => $this->whenCounted('users'),
        ];
    }
}

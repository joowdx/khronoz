<?php

namespace App\Http\Resources;

use App\Models\Terminal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Terminal` interface in resources/js/types/index.d.ts.
 *
 * @mixin Terminal
 */
class TerminalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workgroup_id' => $this->workgroup_id,
            'workgroup' => $this->whenLoaded(
                'workgroup',
                fn () => $this->workgroup === null ? null : WorkgroupResource::make($this->workgroup)->resolve(),
            ),
            'code' => $this->code,
            'name' => $this->name,
            'serial' => $this->serial,
            'kind' => $this->kind->value,
            'protocol' => $this->protocol->value,
            'host' => $this->host,
            'port' => $this->port,
            'has_secret' => $this->secret !== null,
            'active' => $this->active,
            'seen_at' => $this->seen_at?->toDateTimeString(),
            'synced_at' => $this->synced_at?->toDateTimeString(),
            'stamp' => $this->stamp,
            'enrolled_count' => $this->whenCounted('enrolled'),
            'timelogs_count' => $this->whenCounted('timelogs'),
            // `enrolled_count` is scoped to today, so a device whose only
            // enrollments have ended was offered Remove and then refused.
            'enrollments_count' => $this->whenCounted('enrollments'),
            'syncs_count' => $this->whenCounted('syncs'),
            'last_import_at' => $this->whenHas('last_import_at'),
        ];
    }
}

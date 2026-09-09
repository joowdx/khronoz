<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Unit` interface in resources/js/types/index.d.ts (Task 6).
 *
 * `head` is present only when the controller eager-loads `head`; absent
 * (rather than null) means "not loaded", the same convention EmployeeResource
 * uses for `current_deployment`. The units tree is built client-side from a
 * flat list keyed by `parent_id`, so this resource carries no nested
 * `children` — see task-6-brief.md's units/index composition notes.
 *
 * @mixin \App\Models\Unit
 */
class UnitResource extends JsonResource
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
            'parent_id' => $this->parent_id,
            'kind' => $this->kind,
            'code' => $this->code,
            'name' => $this->name,
            'head_id' => $this->head_id,
            'head' => $this->whenLoaded('head', fn ($head) => EmployeeResource::make($head)->resolve()),
        ];
    }
}

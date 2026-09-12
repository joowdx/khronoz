<?php

namespace App\Http\Resources;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Shift` interface in resources/js/types/index.d.ts.
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $slots = $this->slots ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slots' => $slots,
            'required' => $this->required,
            'flex' => $this->flex,
            'remote' => $this->remote,
            'trust' => $this->trust,
            'color' => $this->color,
            'kind' => match (true) {
                $slots !== [] => 'working',
                (bool) $this->remote => 'remote',
                default => 'off',
            },
            'origin_id' => $this->origin_id,
            // `origin_id` is nullable and points at a platform-owned row, so
            // the closure form is required twice over — resources.md.
            'origin' => $this->whenLoaded('origin', fn (Shift $origin) => ShiftResource::make($origin)->resolve()),
        ];
    }
}

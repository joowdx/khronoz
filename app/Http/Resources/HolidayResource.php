<?php

namespace App\Http\Resources;

use App\Models\Holiday;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Holiday` interface in resources/js/types/index.d.ts.
 *
 * @mixin Holiday
 */
class HolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'name' => $this->name,
            'type' => ['value' => $this->type->value, 'label' => $this->type->label()],
            'reference' => $this->reference,
            'declared_at' => $this->declared_at->toDateTimeString(),
            // Declared by the platform for everyone, so this agency may read
            // it and not change it (HolidayPolicy).
            'national' => $this->agency_id === app(Tenant::class)->platformId(),
        ];
    }
}

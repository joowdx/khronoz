<?php

namespace App\Http\Resources;

use App\Models\Holiday;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Holiday` interface in resources/js/types/index.d.ts.
 *
 * `national` is computed rather than sent as a raw `agency_id` comparison the
 * page would have to make: this is the one table read under
 * AgencyOrPlatformScope, so every list is a mix of the tenant's own rows and
 * the platform agency's, and "can I edit this" is the question every row
 * raises. Deriving it once here keeps the page from re-deciding it per row and
 * getting it wrong somewhere.
 *
 * @mixin Holiday
 */
class HolidayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'name' => $this->name,
            'type' => $this->type->value,
            'reference' => $this->reference,
            'declared_at' => $this->declared_at->toDateTimeString(),
            // Declared by the platform for everyone, so this agency may read
            // it and not change it (HolidayPolicy).
            'national' => $this->agency_id === app(Tenant::class)->platformId(),
        ];
    }
}

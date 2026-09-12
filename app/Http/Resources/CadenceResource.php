<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches the `Cadence` interface in resources/js/types/index.d.ts.
 * @mixin \App\Models\Cadence
 */
class CadenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name,
            'kind' => ['value' => $this->kind->value, 'label' => ucfirst($this->kind->value)],
            'rules' => $this->rules, 'anchor' => $this->anchor?->toDateString(),
            'preferred' => $this->preferred, 'retired_at' => $this->retired_at?->toDateTimeString(),
        ];
    }
}

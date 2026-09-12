<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches the `LedgerPolicy` interface in resources/js/types/index.d.ts.
 * @mixin \App\Models\Policy
 */
class PolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'workgroup_id' => $this->workgroup_id, 'employee_id' => $this->employee_id,
            'template' => $this->template, 'roles' => $this->roles,
            'supervisor' => $this->supervisor, 'head_kind' => $this->head_kind,
        ];
    }
}

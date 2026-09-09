<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `AuthUser` interface in resources/js/types/index.d.ts:
 * `permissions` crosses the wire as plain permission values (not enum
 * objects) and `platform` as a real boolean, not the agency_id it is derived
 * from. `invited_at` and `email_verified_at` are extra keys the type does
 * not declare — harmless for the shared `auth.user` prop, and needed by
 * Task 9's user list.
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'permissions' => $this->permissions->map(fn (Permission $permission) => $permission->value)->all(),
            'platform' => $this->isPlatform(),
            'employee_id' => $this->employee_id,
            'invited_at' => $this->invited_at,
            'email_verified_at' => $this->email_verified_at,
        ];
    }
}

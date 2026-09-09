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
 * `permission_groups` is a second, additive representation for display (the
 * users list's access tooltip): the permissions this user actually holds,
 * grouped by Permission::group() with their label(). It does not replace
 * `permissions`, which stays a flat array of values so useCan() and the
 * shared `auth.user` prop keep working unchanged.
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
            'permission_groups' => $this->permissions
                ->groupBy(fn (Permission $permission) => $permission->group())
                ->map(fn ($permissions) => $permissions->map(fn (Permission $permission) => [
                    'value' => $permission->value,
                    'label' => $permission->label(),
                ])->values()->all())
                ->all(),
            'platform' => $this->isPlatform(),
            'employee_id' => $this->employee_id,
            'invited_at' => $this->invited_at,
            'email_verified_at' => $this->email_verified_at,
        ];
    }
}

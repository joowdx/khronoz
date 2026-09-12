<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Enums\Preset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Matches the `AuthUser` interface in resources/js/types/index.d.ts.
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
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
            'access' => $this->access(),
            'status' => $this->status(),
            'invitation_url' => $this->invitationUrl(),
            'platform' => $this->isPlatform(),
            'employee_id' => $this->employee_id,
            'invited_at' => $this->invited_at,
            'email_verified_at' => $this->email_verified_at,
        ];
    }

    /** @return array{label: string, preset: string|null} */
    private function access(): array
    {
        $preset = $this->matchingPreset();
        $count = $this->permissions->count();

        return [
            'label' => match (true) {
                $preset !== null => $preset->label(),
                $count === 0 => 'No permissions',
                default => $count.' '.Str::plural('permission', $count),
            },
            'preset' => $preset?->value,
        ];
    }

    private function matchingPreset(): ?Preset
    {
        $held = $this->permissions->map(fn (Permission $permission) => $permission->value)->sort()->values()->all();

        foreach (Preset::cases() as $preset) {
            $bundle = collect($preset->permissions())
                ->map(fn (Permission $permission) => $permission->value)
                ->sort()->values()->all();

            if ($held === $bundle) {
                return $preset;
            }
        }

        return null;
    }

    private function status(): string
    {
        return $this->email_verified_at === null ? 'invited' : 'active';
    }

    private function invitationUrl(): ?string
    {
        if ($this->email_verified_at !== null) {
            return null;
        }

        return URL::temporarySignedRoute('invite.accept', now()->addDays(7), ['user' => $this->resource]);
    }
}

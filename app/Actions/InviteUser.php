<?php

namespace App\Actions;

use App\Enums\Preset;
use App\Models\User;
use App\Notifications\InviteNotification;
use App\Tenancy\Tenant;
use Illuminate\Support\Str;

final class InviteUser
{
    public function __construct(private Tenant $tenant) {}

    /**
     * @param  array{name: string, email: string, permissions?: array<int, string>}  $attributes
     */
    public function handle(array $attributes, ?Preset $preset = null): User
    {
        $permissions = $preset
            ? collect($preset->permissions())->map(fn ($permission) => $permission->value)->all()
            : ($attributes['permissions'] ?? []);

        $user = User::create([
            ...$attributes,
            'agency_id' => $this->tenant->id(),
            'password' => Str::password(32),
            'invited_at' => now(),
            'permissions' => $permissions,
        ]);

        $user->notify(new InviteNotification);

        return $user;
    }
}

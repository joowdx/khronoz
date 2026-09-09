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
     * Create an invited user in the current tenant and email them a signed
     * link to accept. The password is random and never shared with anyone —
     * the only way in is the invite link, which sets a real one.
     *
     * $preset, when given, expands to its permission bundle and overrides
     * whatever $attributes['permissions'] holds — a convenience for a future
     * caller that only knows a bundle's name. Task 9's own controller instead
     * resolves the exact permission list client-side through the picker and
     * always passes it explicitly, leaving $preset null; Task 7's tests call
     * this with the plain array and no second argument, so that shape keeps
     * working unchanged.
     *
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

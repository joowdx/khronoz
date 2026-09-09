<?php

namespace App\Actions;

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
     * @param  array{name: string, email: string, permissions: array<int, string>}  $attributes
     */
    public function handle(array $attributes): User
    {
        $user = User::create([
            ...$attributes,
            'agency_id' => $this->tenant->id(),
            'password' => Str::password(32),
            'invited_at' => now(),
        ]);

        $user->notify(new InviteNotification);

        return $user;
    }
}

<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChangePassword
{
    public function handle(User $user, string $password, ?string $session = null): User
    {
        return DB::transaction(function () use ($user, $password, $session): User {
            $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $owner->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'pending_email' => null,
                'pending_email_token' => null,
                'pending_email_expires_at' => null,
            ])->save();

            DB::table('sessions')->where('user_id', $owner->getKey())
                ->when($session, fn ($query) => $query->where('id', '!=', $session))->delete();
            DB::table('resets')->where('email', $owner->email)->delete();

            return $owner;
        });
    }
}

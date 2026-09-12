<?php

namespace App\Actions;

use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

final class ConfirmEmailChange
{
    public function handle(User $user, string $token): void
    {
        try {
            [$old, $email] = DB::transaction(function () use ($user, $token): array {
                $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                abort_unless($owner->pending_email && $owner->pending_email_token
                    && $owner->pending_email_expires_at?->isFuture()
                    && hash_equals($owner->pending_email_token, hash('sha256', $token)), 403, 'This email change is no longer available.');

                $old = $owner->email;
                $email = $owner->pending_email;
                $owner->forceFill([
                    'email' => $email,
                    'email_verified_at' => now(),
                    'pending_email' => null,
                    'pending_email_token' => null,
                    'pending_email_expires_at' => null,
                ])->save();
                DB::table('resets')->whereIn('email', [$old, $email])->delete();

                return [$old, $email];
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages(['email' => 'Already in use']);
        }

        $user->refresh();
        Notification::route('mail', $old)->notify(new EmailChangedNotification($email));
    }
}

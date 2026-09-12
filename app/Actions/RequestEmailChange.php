<?php

namespace App\Actions;

use App\Models\User;
use App\Notifications\EmailChangeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RequestEmailChange
{
    public function handle(User $user, string $email): void
    {
        $email = Str::lower(trim($email));
        $token = Str::random(64);
        $expires = now()->addHour();

        DB::transaction(function () use ($user, $email, $token, $expires): void {
            $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => 'Already in use']);
            }

            $owner->forceFill([
                'pending_email' => $email,
                'pending_email_token' => hash('sha256', $token),
                'pending_email_expires_at' => $expires,
            ])->save();
        });

        $user->refresh();
        $url = URL::temporarySignedRoute('settings.email.verify', $expires, ['token' => $token]);
        Notification::route('mail', $email)->notify(new EmailChangeNotification($url));
    }
}

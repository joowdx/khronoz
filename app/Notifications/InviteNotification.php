<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class InviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to khronoz")
            ->line($notifiable->agency->name.' set up your account.')
            ->action('Accept invitation', URL::temporarySignedRoute('invite.accept', now()->addDays(7), ['user' => $notifiable]))
            ->line('This link works for 7 days.');
    }
}

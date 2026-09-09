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
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * Safe to send more than once — Task 9's re-send button does exactly
     * that — because every send mints a fresh 7-day signed URL rather than
     * reusing one computed elsewhere: an earlier email's link keeps working
     * alongside the new one until it expires on its own.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to khronoz")
            ->line($notifiable->agency->name.' set up your account.')
            ->action('Accept invitation', URL::temporarySignedRoute('invite.accept', now()->addDays(7), ['user' => $notifiable]))
            ->line('This link works for 7 days.');
    }
}

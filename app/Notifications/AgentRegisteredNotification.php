<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent instead of the usual click-to-verify email when an agent registers.
 * Agent accounts are always reviewed by an admin (who verifies + activates
 * them from the Users page), so there's no self-service link to send here —
 * this just sets expectations while they wait.
 */
class AgentRegisteredNotification extends Notification
{
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $name = $notifiable->first_name ?: $notifiable->name;

        return (new MailMessage)
            ->subject("We've received your REOZOM agent application")
            ->greeting("Welcome to REOZOM, {$name}!")
            ->line('Thanks for registering as an agent.')
            ->line("Our team is reviewing your details now — there's nothing else you need to do.")
            ->line("We'll email you as soon as your account has been verified and approved.");
    }
}

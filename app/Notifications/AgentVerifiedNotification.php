<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an admin verifies an agent's account from the Users page —
 * the agent-specific counterpart to WelcomeNotification (which handles
 * the seller/buyer self-service verify-link flow instead).
 */
class AgentVerifiedNotification extends Notification
{
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $loginUrl = rtrim(config('app.frontend_url'), '/').'/auth/login';
        $name = $notifiable->first_name ?: $notifiable->name;

        return (new MailMessage)
            ->subject('Your REOZOM agent account is verified')
            ->greeting("You're verified, {$name}!")
            ->line('An administrator has reviewed and verified your account details.')
            ->action('Sign In', $loginUrl)
            ->line("If your account is still pending final approval, you'll be notified once you're able to sign in.");
    }
}

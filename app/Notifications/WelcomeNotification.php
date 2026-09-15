<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once, the moment a user's email transitions from unverified to
 * verified — whether that happens via their own signed link click or an
 * admin marking it verified from the Users page.
 */
class WelcomeNotification extends Notification
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
            ->subject('Welcome to REOZOM!')
            ->greeting("Welcome to REOZOM, {$name}!")
            ->line("Your email is verified and your account is ready to go.")
            ->action('Sign In', $loginUrl)
            ->line('Smart Listings. Simple Process. We\'re glad you\'re here.');
    }
}

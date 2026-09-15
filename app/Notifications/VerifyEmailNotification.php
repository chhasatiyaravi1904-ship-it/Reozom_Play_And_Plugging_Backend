<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    /**
     * REOZOM-branded copy for the same signed verification link the base
     * Illuminate notification builds — only the message content differs.
     */
    protected function buildMailMessage($url)
    {
        return (new MailMessage)
            ->subject('Verify your email address')
            ->greeting('Welcome to REOZOM!')
            ->line("You're almost set — confirm your email address to activate your account.")
            ->action('Verify Email Address', $url)
            ->line('If you did not create a REOZOM account, no further action is required.');
    }
}

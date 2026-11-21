<?php

namespace App\Notifications;

use App\Models\Payout;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the agent: their withdrawal was paid (with the transfer reference) or rejected (with the reason).
 */
class WithdrawalProcessedNotification extends Notification
{
    public function __construct(private readonly Payout $payout) {}

    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $amount = '$'.number_format($this->payout->amount / 100, 2);
        $name = $notifiable->first_name ?: $notifiable->name;
        $url = rtrim(config('app.frontend_url'), '/').'/agent/payouts';
        $last4 = substr((string) ($this->payout->bank_details['account_number'] ?? ''), -4);

        if ($this->payout->status === Payout::STATUS_PAID) {
            return (new MailMessage)
                ->subject("Your withdrawal of {$amount} has been sent")
                ->greeting("Good news, {$name}!")
                ->line("We sent {$amount} to your bank account".($last4 ? " ending in {$last4}" : '').'.')
                ->line("Transfer reference: {$this->payout->reference}")
                ->line('Bank transfers usually arrive within 1-3 business days.')
                ->action('View Payouts', $url);
        }

        return (new MailMessage)
            ->subject("Your withdrawal of {$amount} was not processed")
            ->greeting("Hi {$name},")
            ->line("Your withdrawal request for {$amount} was rejected.")
            ->line("Reason: {$this->payout->failure_message}")
            ->line('The amount is back in your available balance.')
            ->action('View Payouts', $url);
    }
}

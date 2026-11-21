<?php

namespace App\Notifications;

use App\Models\Payout;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To admins: an agent asked for a withdrawal that needs a bank transfer.
 * Bank details are deliberately not in the email; they are shown on the admin Payouts page.
 */
class WithdrawalRequestedNotification extends Notification
{
    public function __construct(private readonly Payout $payout) {}

    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $agent = $this->payout->agent;
        $amount = '$'.number_format($this->payout->amount / 100, 2);

        return (new MailMessage)
            ->subject("Withdrawal request: {$amount} from {$agent?->name}")
            ->greeting('New withdrawal request')
            ->line("{$agent?->name} ({$agent?->email}) asked to withdraw {$amount}.")
            ->line('Send the bank transfer, then mark the request as paid with the transfer reference.')
            ->action('Open Payouts', rtrim(config('app.frontend_url'), '/').'/admin/payouts');
    }
}

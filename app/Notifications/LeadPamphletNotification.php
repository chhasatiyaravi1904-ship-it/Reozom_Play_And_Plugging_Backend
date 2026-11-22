<?php

namespace App\Notifications;

use App\Models\ListingOffer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the buyer and purchasers of an offer on a home built before 1978: the EPA pamphlet
 * "Protect Your Family From Lead In Your Home" (original Reozom OfferNotificationsService).
 */
class LeadPamphletNotification extends Notification
{
    public function __construct(private readonly ListingOffer $offer) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $listing = $this->offer->listing;
        $address = trim("{$listing?->address}, {$listing?->city}, {$listing?->state} {$listing?->zip}", ' ,');

        return (new MailMessage)
            ->subject('Protect Your Family From Lead In Your Home')
            ->greeting('Lead-based paint information')
            ->line("The home at {$address} was built before 1978. Federal law requires that buyers receive the EPA pamphlet \"Protect Your Family From Lead In Your Home\".")
            ->line("It is attached to this email for offer {$this->offer->reference_code}.")
            ->attach(resource_path('documents/lead-pamphlet.pdf'), [
                'as' => 'Protect Your Family From Lead In Your Home.pdf',
                'mime' => 'application/pdf',
            ]);
    }
}

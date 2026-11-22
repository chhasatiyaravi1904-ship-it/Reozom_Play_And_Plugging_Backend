<?php

namespace App\Notifications;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the listing owner: someone else opened one of the listing's uploaded documents
 * (original Reozom DocumentsViewed event / sendEmailNotificationToSellerThatDocumentsHasBeenViewed).
 */
class DocumentsViewedNotification extends Notification
{
    public function __construct(
        private readonly Listing $listing,
        private readonly User $viewer,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $address = trim("{$this->listing->address}, {$this->listing->city}, {$this->listing->state} {$this->listing->zip}", ' ,');

        return (new MailMessage)
            ->subject('Your listing documents have been viewed')
            ->greeting('Documents viewed')
            ->line("{$this->viewer->name} viewed the documents you uploaded for {$address}.")
            ->action('Open documents', rtrim((string) config('app.frontend_url'), '/')."/listings/{$this->listing->id}/documents");
    }
}

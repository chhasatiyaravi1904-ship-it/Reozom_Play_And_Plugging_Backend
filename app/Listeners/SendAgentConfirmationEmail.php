<?php

namespace App\Listeners;

use App\Events\AgentAdded;

class SendAgentConfirmationEmail
{
    public function handle(AgentAdded $event): void
    {
        $event->user->sendEmailVerificationNotification();
    }
}

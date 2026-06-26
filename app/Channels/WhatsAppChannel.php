<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    /**
     * Send the given notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);
        $data = $message->toArray();

        // Simulate sending by logging the formatted WhatsApp message
        Log::info(sprintf(
            "WhatsApp Sent to %s: %s [Template: %s, Params: %s]",
            $data['to'],
            $data['content'],
            $data['template'],
            json_encode($data['params'])
        ));
    }
}

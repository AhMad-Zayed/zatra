<?php

namespace App\Contracts;

use App\Models\Booking;

interface NotificationDriverInterface
{
    /**
     * Send a notification to the customer.
     * 
     * @param Booking $booking The booking context.
     * @param string $message The message body or template reference.
     * @param array $attachments Array of local file paths (e.g., PDF ticket).
     * @return bool True if queued/sent successfully.
     */
    public function send(Booking $booking, string $message, array $attachments = []): bool;
}

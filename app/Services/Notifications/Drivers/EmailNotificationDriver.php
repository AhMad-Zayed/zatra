<?php

namespace App\Services\Notifications\Drivers;

use App\Contracts\NotificationDriverInterface;
use App\Models\Booking;
use Illuminate\Support\Facades\Mail;
use App\Mail\BookingConfirmedMail;
use Exception;
use Illuminate\Support\Facades\Log;

class EmailNotificationDriver implements NotificationDriverInterface
{
    public function send(Booking $booking, string $message, array $attachments = []): bool
    {
        try {
            $customerEmail = $booking->customer->email;
            
            if (!$customerEmail) {
                Log::warning("Email Notification Skipped: No email found for Customer {$booking->customer_id}.");
                return false;
            }

            Mail::to($customerEmail)->send(new BookingConfirmedMail($booking, $message, $attachments));
            
            return true;
            
        } catch (Exception $e) {
            Log::error("Email Notification Failed (Booking: {$booking->id}): " . $e->getMessage());
            throw $e; // Throw to trigger Job backoff/retry
        }
    }
}

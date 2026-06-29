<?php

namespace App\Listeners;

use App\Events\BookingCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendBookingNotifications implements \Illuminate\Contracts\Queue\ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(BookingCreated $event): void
    {
        $booking = $event->booking;
        $tenant = $booking->tenant;

        if (!$tenant || !$booking->customer) {
            return;
        }

        if ($tenant->enable_email_alerts && !empty($booking->customer->email)) {
            try {
                \Illuminate\Support\Facades\Log::info("Sending Email to " . $booking->customer->email . " for booking " . $booking->pnr);
                \Illuminate\Support\Facades\Mail::to($booking->customer->email)
                    ->send(new \App\Mail\BookingTicketMail($booking));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to send booking email: " . $e->getMessage());
            }
        }

        if ($tenant->enable_whatsapp_alerts) {
            \Illuminate\Support\Facades\Log::info("Sending WhatsApp to " . $booking->customer->phone . " for booking " . $booking->pnr);
            // TODO: AtlaHub WhatsApp API Integration
        }

        if ($tenant->enable_sms_alerts) {
            \Illuminate\Support\Facades\Log::info("Sending SMS to " . $booking->customer->phone . " for booking " . $booking->pnr);
            // TODO: SMS Gateway Integration
        }
    }
}

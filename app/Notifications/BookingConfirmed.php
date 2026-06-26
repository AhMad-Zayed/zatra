<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Channels\WhatsAppChannel;
use App\Channels\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingConfirmed extends Notification
{
    use Queueable;

    public Booking $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $phone = $notifiable->phone ?? '';
        $ref = $this->booking->reference ?? '';

        return WhatsAppMessage::create()
            ->to($phone)
            ->template('booking_confirmed')
            ->params([
                'reference' => $ref,
            ])
            ->content("Dear Customer, your booking {$ref} is confirmed! Your tickets and hotel vouchers are ready.");
    }
}

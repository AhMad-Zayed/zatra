<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Channels\WhatsAppChannel;
use App\Channels\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingPending extends Notification
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
        $amount = number_format($this->booking->total_amount, 2);

        return WhatsAppMessage::create()
            ->to($phone)
            ->template('booking_pending')
            ->params([
                'reference' => $ref,
                'amount' => $amount,
            ])
            ->content("Dear Customer, your booking {$ref} has been received with total amount {$amount} ILS. Please proceed with payment.");
    }
}

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
        return ['mail', WhatsAppChannel::class];
    }

    public function toMail(object $notifiable)
    {
        $ref = $this->booking->pnr ?? $this->booking->reference ?? '';
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject("تأكيد الحجز - {$ref}")
            ->view('mail.booking-confirmed', [
                'booking' => $this->booking,
                'tenant' => $this->booking->tenant,
            ]);
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

<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Channels\WhatsAppChannel;
use App\Channels\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BookingCancelled extends Notification
{
    use Queueable;

    public Booking $booking;
    public string $reason;

    public function __construct(Booking $booking, string $reason = '')
    {
        $this->booking = $booking;
        $this->reason  = $reason;
    }

    public function via(object $notifiable): array
    {
        return ['mail', WhatsAppChannel::class];
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        $ref = $this->booking->pnr ?? '';

        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject("إلغاء الحجز - {$ref}")
            ->view('mail.booking-cancelled', [
                'booking' => $this->booking,
                'tenant'  => $this->booking->tenant,
                'reason'  => $this->reason,
            ]);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $phone  = $notifiable->phone ?? '';
        $ref    = $this->booking->pnr ?? '';
        $name   = $notifiable->name ?? 'العميل الكريم';
        $reason = $this->reason ? " السبب: {$this->reason}." : '';

        return WhatsAppMessage::create()
            ->to($phone)
            ->template('booking_cancelled')
            ->params([
                'reference' => $ref,
                'reason'    => $this->reason,
            ])
            ->content("عزيزنا {$name}، نأسف لإبلاغك بأن حجزك رقم {$ref} قد تم إلغاؤه.{$reason} للاستفسار تواصل معنا.");
    }
}

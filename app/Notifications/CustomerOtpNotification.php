<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Channels\Messages\WhatsAppMessage;
use Illuminate\Notifications\Notification;

class CustomerOtpNotification extends Notification
{
    public string $otp;

    public function __construct(string $otp)
    {
        $this->otp = $otp;
    }

    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $phone = $notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable
            ? ($notifiable->routes[WhatsAppChannel::class] ?? $notifiable->routes['whatsapp'] ?? '')
            : ($notifiable->phone ?? '');

        return WhatsAppMessage::create()
            ->to($phone)
            ->template('customer_otp')
            ->params(['otp' => $this->otp])
            ->content("رمز التحقق الخاص بك لشركة زاتارا للسياحة هو: {$this->otp}");
    }
}

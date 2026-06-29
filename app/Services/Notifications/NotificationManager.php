<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationDriverInterface;
use App\Services\Notifications\Drivers\EmailNotificationDriver;
use App\Services\Notifications\Drivers\WhatsAppNotificationDriver;
use InvalidArgumentException;

class NotificationManager
{
    public static function resolve(string $channel): NotificationDriverInterface
    {
        return match ($channel) {
            'email' => new EmailNotificationDriver(),
            'whatsapp' => new WhatsAppNotificationDriver(),
            default => throw new InvalidArgumentException("Unsupported notification channel: {$channel}"),
        };
    }
}

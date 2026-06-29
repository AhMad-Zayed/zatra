<?php

namespace App\Services\Notifications\Drivers;

use App\Contracts\NotificationDriverInterface;
use App\Models\Booking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class WhatsAppNotificationDriver implements NotificationDriverInterface
{
    /**
     * Send a WhatsApp message via Meta Graph API.
     */
    public function send(Booking $booking, string $message, array $attachments = []): bool
    {
        try {
            $customerPhone = $booking->customer->phone;
            if (empty($customerPhone)) {
                return false;
            }

            $formattedPhone = preg_replace('/[^0-9]/', '', $customerPhone);
            $whatsappToken = $booking->tenant->settings['whatsapp_token'] ?? config('services.whatsapp.token');
            $whatsappPhoneId = config('services.whatsapp.phone_id');

            if (!$whatsappToken || !$whatsappPhoneId) {
                Log::error('WhatsApp Error: Missing Credentials.');
                return false;
            }

            $endpoint = "https://graph.facebook.com/v17.0/{$whatsappPhoneId}/messages";

            // إعداد Payload القالب المعتمد من Meta
            $response = Http::withToken($whatsappToken)
                ->timeout(10)
                ->retry(3, 1000)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $formattedPhone,
                    'type' => 'template',
                    'template' => [
                        'name' => 'booking_confirmation_v1', // اسم القالب المعتمد
                        'language' => [
                            'code' => 'ar' // لغة القالب
                        ],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    [
                                        'type' => 'text',
                                        'text' => $booking->pnr // المتغير الأول {{1}}
                                    ],
                                    [
                                        'type' => 'text',
                                        'text' => number_format($booking->balance_due, 2) . ' دولار' // المتغير الثاني {{2}}
                                    ]
                                ]
                            ]
                        ]
                    ],
                ]);

            if ($response->failed()) {
                $errorData = $response->json('error.message', $response->body());
                throw new Exception("WhatsApp API Error: {$errorData}");
            }

            return true;
        } catch (Exception $e) {
            Log::error("WhatsApp Notification Failed (Booking: {$booking->id}): " . $e->getMessage());
            throw $e;
        }
    }
}

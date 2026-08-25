<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Enums\PaymentType;

/**
 * P0-6: thin backward-compatible wrapper. All payment-recording/reversal business logic
 * now lives in BookingService (the single canonical authority, consistent with P0-7's
 * passenger-mutation centralization) — this class exists only so existing callers with this
 * exact signature (tests, RunQA.php) keep working unchanged. Contains no independent logic.
 */
class PaymentService
{
    public function recordPayment(Booking $booking, float $amount, string $method, User $receivedBy, PaymentType $type = PaymentType::DEPOSIT, ?string $currency = null): Payment
    {
        return app(BookingService::class)->recordPayment($booking, $amount, $method, $receivedBy, $type, null, $currency);
    }

    public function reversePayment(Payment $original, string $reason, User $receivedBy): Payment
    {
        return app(BookingService::class)->reversePayment($original, $reason, $receivedBy);
    }
}

<?php

namespace App\Contracts;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * Initialize a payment session/URL for the given booking.
     * Must inject booking_id and tenant_id into the gateway metadata.
     */
    public function initializePayment(Booking $booking, float $amount): array;

    /**
     * Verify a callback/webhook request from the gateway.
     * Returns true if the payment mathematically checks out and the signature is valid.
     */
    public function verifyCallback(Request $request): bool;

    /**
     * Process the payment response into a standardized DTO/array.
     * Must return ['transaction_id' => string, 'amount' => float, 'method' => string, 'booking_id' => int, 'tenant_id' => int]
     */
    public function processPayment(Request $request): array;

    /**
     * Refund a previously captured payment.
     */
    public function refundPayment(Payment $payment): bool;
}

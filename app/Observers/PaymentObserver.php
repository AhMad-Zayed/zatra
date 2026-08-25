<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\BookingService;

class PaymentObserver
{
    /**
     * Handle the Payment "created" event.
     */
    public function created(Payment $payment): void
    {
        // P0-6: defense-in-depth for any Payment::create() not yet routed through
        // BookingService::recordPayment()/reversePayment() (which already call
        // recalculateTotals() themselves). Idempotent by construction — recalculating twice
        // from the same underlying payment/passenger state produces the same result, so this
        // firing in addition to a canonical call is redundant but harmless, not corrupting.
        app(BookingService::class)->recalculateTotals($payment->booking);
    }
}

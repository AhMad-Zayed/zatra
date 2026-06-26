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
        app(BookingService::class)->recalculateFinancialStatus($payment->booking);
    }
}

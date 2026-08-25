<?php

namespace App\Services\Payments;

use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\BookingService;
use Illuminate\Support\Facades\DB;

class ProcessGatewayPaymentService
{
    /**
     * Safely process a gateway payment with idempotency and pessimistic locking.
     *
     * @param array $paymentData Standardized data from the gateway (transaction_id, amount, method, booking_id, tenant_id)
     * @return Payment|null Returns the Payment model if successful, or null if idempotent skip.
     */
    public function execute(array $paymentData): ?Payment
    {
        return DB::transaction(function () use ($paymentData) {

            // 1. Correct Pessimistic Locking Query (Fetch fresh to prevent race conditions).
            // Locking here (before the idempotency check) is what makes that check race-safe:
            // a second webhook delivery for the same booking blocks until this transaction
            // commits, then sees the payment already created below.
            $booking = Booking::where('id', $paymentData['booking_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Idempotency Check: Prevent double-crediting on webhook retries.
            if (Payment::where('reference_number', $paymentData['transaction_id'])->exists()) {
                // Return null to signify an idempotent skip
                return null;
            }

            // 3-5. Delegate payment creation + totals/status recalculation to the same
            // canonical, currency-checked path every other payment-creation surface uses
            // (BookingService::recordPayment(), P0-6) instead of the hand-rolled Payment::create()
            // + manual total/balance/status math this used to do inline — which never set
            // Payment.currency at all, never validated it against the booking's currency, never
            // recomputed grand_total from live passengers/addons/package/discount (silently
            // trusting whatever grand_total already held), never guarded against writing over an
            // already-Cancelled booking, and wrote no activity log entry. $receivedBy is null
            // (no authenticated user in a webhook context) and $enforceBalanceGuard is false,
            // preserving this path's pre-existing behavior of never rejecting an overpayment —
            // recordPayment()'s own docblock anticipates exactly this caller.
            return app(BookingService::class)->recordPayment(
                $booking,
                (float) $paymentData['amount'],
                $paymentData['method'],
                null,
                PaymentType::PAYMENT,
                $paymentData['transaction_id'],
                $booking->currency,
                false
            );
        });
    }
}

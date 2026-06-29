<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\Payment;
use App\Enums\PaymentStatus;
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
            
            // 1. Correct Pessimistic Locking Query (Fetch fresh to prevent race conditions)
            $booking = Booking::where('id', $paymentData['booking_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Idempotency Check: Prevent double-crediting
            if (Payment::where('transaction_id', $paymentData['transaction_id'])->exists()) {
                // Return null to signify an idempotent skip
                return null;
            }

            // 3. Create the Immutable Financial Ledger Record
            $payment = Payment::create([
                'tenant_id' => $paymentData['tenant_id'],
                'booking_id' => $booking->id,
                'transaction_id' => $paymentData['transaction_id'],
                'amount' => $paymentData['amount'],
                'method' => $paymentData['method'],
                'status' => 'Completed',
            ]);

            // 4. Calculate Financial Totals
            $totalPaid = $booking->payments()->where('status', 'Completed')->sum('amount');
            $balanceDue = max(0, $booking->grand_total - $totalPaid);

            // 5. Update Booking Statuses
            $booking->update([
                'total_paid' => $totalPaid,
                'balance_due' => $balanceDue,
                'payment_status' => $balanceDue <= 0 ? PaymentStatus::Paid : PaymentStatus::Partial,
                // Transition booking status to Confirmed only if fully paid
                'booking_status' => $balanceDue <= 0 ? \App\Enums\BookingStatus::Confirmed : $booking->booking_status,
            ]);

            return $payment;
        });
    }
}

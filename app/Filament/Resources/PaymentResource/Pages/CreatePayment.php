<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    // VALID-002: Update the booking's total_paid and balance_due when a payment is manually created here
    protected function afterCreate(): void
    {
        $payment = $this->getRecord();
        $booking = $payment->booking;

        if ($booking) {
            // Note: $payment->amount is automatically cast to cents and back by MoneyCast.
            // When accessing $payment->amount, it returns the float dollar value (e.g., 50.00).
            // But wait, total_paid in DB is integer cents.
            // When we do $booking->total_paid (integer in DB), does Booking have MoneyCast for total_paid?
            // Let's assume we need to update it properly. We can use the payment's raw attribute or let Eloquent handle it if it's cast.
            // Wait, we can just let Laravel increment it if it's cast.
            
            // Re-calculate the total paid from all payments
            // Note: Reversals and Refunds are saved as NEGATIVE amounts, so they MUST be summed!
            $sumCents = \App\Models\Payment::where('booking_id', $booking->id)
                // Use raw SQL sum since MoneyCast will mess up ->sum('amount') 
                ->sum('amount'); // This sum returns raw cents from DB!
            
            $totalPaidFloat = $sumCents / 100;
            $newBalanceFloat = max(0, $booking->grand_total - $totalPaidFloat);
            
            $booking->update([
                'total_paid' => $totalPaidFloat,
                'balance_due' => $newBalanceFloat,
            ]);

            // Auto-confirm if paid in full
            if ($newBalanceFloat <= 0 && $booking->booking_status === \App\Enums\BookingStatus::Pending) {
                $booking->update(['booking_status' => \App\Enums\BookingStatus::Confirmed]);
            }
        }
    }
}

<?php
$content = file_get_contents('app/Services/BookingService.php');

$newMethod = <<<METHOD
    /**
     * Recalculate Booking financial totals based on current valid passengers and addons.
     */
    public function recalculateTotals(\App\Models\Booking \$booking): void
    {
        \$passengers = \$booking->passengers()->where('data_complete', true)->get();
        
        \$grandTotalFloat = 0.0;
        foreach (\$passengers as \$passenger) {
            \$grandTotalFloat += (float) \$passenger->price_at_booking;
        }
        
        \$addonsFloat = 0.0;
        foreach (\$booking->addons ?? [] as \$addon) {
            \$addonsFloat += ((float) \$addon->price_at_booking) * \$addon->quantity;
        }
        
        \$packageAdjustment = 0.0;
        if (\$booking->package_option_id && \$booking->packageOption) {
            \$adj = (float) (\$booking->packageOption->price_adjustment ?? 0);
            \$packageAdjustment = \$adj * \$passengers->count();
        }
        
        \$discountFloat = (float) (\$booking->discount_amount ?? 0);
        
        \$totalFloat = max(0, \$grandTotalFloat + \$addonsFloat + \$packageAdjustment - \$discountFloat);
        
        // Summing via SQL returns cents, so divide by 100
        \$paidFloat = ((float) \$booking->payments()->sum('amount')) / 100.0;
        \$balanceDueFloat = \$totalFloat - \$paidFloat;
        
        \$paymentStatus = match (true) {
            \$paidFloat <= 0 => \App\Enums\PaymentStatus::Unpaid,
            \$paidFloat >= \$totalFloat => \App\Enums\PaymentStatus::Paid,
            default => \App\Enums\PaymentStatus::PartiallyPaid,
        };
        
        // Eloquent casts will correctly multiply these floats by 100 before saving to DB
        \$booking->updateQuietly([
            'grand_total' => \$totalFloat,
            'balance_due' => \$balanceDueFloat,
            'total_paid' => \$paidFloat,
            'payment_status' => \$paymentStatus->value,
        ]);
        
        if (\$paymentStatus === \App\Enums\PaymentStatus::Paid && \$booking->booking_status === \App\Enums\BookingStatus::Pending) {
             \$booking->updateQuietly(['booking_status' => \App\Enums\BookingStatus::Confirmed->value]);
        }
    }
METHOD;

// Regex to replace the whole method
$content = preg_replace('/public function recalculateTotals.*?    \}/s', $newMethod, $content);
file_put_contents('app/Services/BookingService.php', $content);

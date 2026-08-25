<?php
$content = file_get_contents('app/Services/BookingService.php');

$newMethod = <<<METHOD
    /**
     * Recalculate Booking financial totals based on current valid passengers and addons.
     */
    public function recalculateTotals(Booking \$booking): void
    {
        \$passengers = \$booking->passengers()->where('data_complete', true)->get();
        
        \$grandTotalCents = 0;
        foreach (\$passengers as \$passenger) {
            \$grandTotalCents += (int) round(\$passenger->getRawOriginal('price_at_booking'));
        }
        
        \$addonsCents = 0;
        foreach (\$booking->addons as \$addon) {
            \$addonsCents += ((int) round(\$addon->getRawOriginal('price_at_booking'))) * \$addon->quantity;
        }
        
        \$packageAdjustment = 0;
        if (\$booking->package_option_id && \$booking->packageOption) {
            \$adj = (int) round(\$booking->packageOption->getRawOriginal('price_adjustment') ?? 0);
            \$packageAdjustment = \$adj * \$passengers->count();
        }
        
        \$discountCents = (int) round(\$booking->getRawOriginal('discount_amount') ?? 0);
        
        \$totalCents = max(0, \$grandTotalCents + \$addonsCents + \$packageAdjustment - \$discountCents);
        
        \$paidCents = (int) \$booking->payments()->sum('amount');
        \$balanceDueCents = \$totalCents - \$paidCents;
        
        \$paymentStatus = match (true) {
            \$paidCents <= 0 => \App\Enums\PaymentStatus::Unpaid,
            \$paidCents >= \$totalCents => \App\Enums\PaymentStatus::Paid,
            default => \App\Enums\PaymentStatus::PartiallyPaid,
        };
        
        // Prevent infinite loops by updating quietly
        \$booking->updateQuietly([
            'grand_total' => \$totalCents,
            'balance_due' => \$balanceDueCents,
            'total_paid' => \$paidCents,
            'payment_status' => \$paymentStatus->value,
        ]);
        
        if (\$paymentStatus === \App\Enums\PaymentStatus::Paid && \$booking->booking_status === \App\Enums\BookingStatus::Pending) {
             \$booking->updateQuietly(['booking_status' => \App\Enums\BookingStatus::Confirmed->value]);
        }
    }
METHOD;

// I will insert it before recalculateFinancialStatus
$content = str_replace('public function recalculateFinancialStatus(Booking $booking): void', $newMethod . "\n\n    public function recalculateFinancialStatus(Booking \$booking): void", $content);

// Also remove the old inventory code in cancelBooking and use InventoryService
$cancelCode = <<<CANCEL
            // Release inventory (seats)
            app(\App\Services\InventoryService::class)->releaseForCancellation(\$booking);
CANCEL;
$content = preg_replace('/\$seatsToRelease = \\\\App\\\\Models\\\\Passenger.*?expires_at\'\s*=> null,\s*\]\);\s*\}/s', $cancelCode, $content);

file_put_contents('app/Services/BookingService.php', $content);
echo "Patched BookingService.php\n";

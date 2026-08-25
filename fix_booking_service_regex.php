<?php
$content = file_get_contents('app/Services/BookingService.php');
// Let's rip out from 'public function recalculateTotals' up to 'public function recalculateFinancialStatus'
$start = strpos($content, 'public function recalculateTotals');
$end = strpos($content, 'public function recalculateFinancialStatus');
$top = substr($content, 0, $start);
$bottom = substr($content, $end);

$newMethod = <<<METHOD
    public function recalculateTotals(\App\Models\Booking \$booking): void
    {
        \$passengers = \$booking->passengers()->where('data_complete', true)->get();
        \$grandTotalFloat = 0.0;
        foreach (\$passengers as \$passenger) { \$grandTotalFloat += (float) \$passenger->price_at_booking; }
        
        \$addonsFloat = 0.0;
        foreach (\$booking->addons ?? [] as \$addon) { \$addonsFloat += ((float) \$addon->price_at_booking) * \$addon->quantity; }
        
        \$packageAdjustment = 0.0;
        if (\$booking->package_option_id && \$booking->packageOption) {
            \$adj = (float) (\$booking->packageOption->price_adjustment ?? 0);
            \$packageAdjustment = \$adj * \$passengers->count();
        }
        \$discountFloat = (float) (\$booking->discount_amount ?? 0);
        \$totalFloat = max(0, \$grandTotalFloat + \$addonsFloat + \$packageAdjustment - \$discountFloat);
        
        \$paidFloat = ((float) \$booking->payments()->sum('amount')) / 100.0;
        \$balanceDueFloat = \$totalFloat - \$paidFloat;
        
        \$paymentStatus = match (true) {
            \$paidFloat <= 0 => \App\Enums\PaymentStatus::Unpaid,
            \$paidFloat >= \$totalFloat => \App\Enums\PaymentStatus::Paid,
            default => \App\Enums\PaymentStatus::PartiallyPaid,
        };
        
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

file_put_contents('app/Services/BookingService.php', $top . $newMethod . $bottom);

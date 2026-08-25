<?php
$content = file_get_contents('app/Services/CreateBookingService.php');

$paymentCode = <<<PAYMENT
            // 7. Process Initial Payment (if provided)
            \$initialPaymentAmount = (float) (\$data['initial_payment_amount'] ?? 0);
            if (\$initialPaymentAmount > 0) {
                if (\$initialPaymentAmount > (\$booking->grand_total / 100)) {
                    // Because grand_total is in cents after recalculation, but the user inputted NIS
                    throw new \Exception("لا يمكن أن تتجاوز الدفعة الأولى إجمالي الحجز.");
                }

                \App\Models\Payment::create([
                    'tenant_id' => \$tenantId,
                    'booking_id' => \$booking->id,
                    'amount' => \$initialPaymentAmount,
                    'currency' => \$tripInstance->tenant->currency ?? 'USD',
                    'payment_method' => \$data['initial_payment_method'] ?? 'cash',
                    'transaction_reference' => 'INITIAL_PAYMENT',
                    'notes' => 'الدفعة الأولى عند إنشاء الحجز',
                ]);
            }
            
            return \$booking;
PAYMENT;

$content = str_replace('return $booking;', $paymentCode, $content);
file_put_contents('app/Services/CreateBookingService.php', $content);

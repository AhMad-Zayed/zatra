<?php
$content = file_get_contents('app/Services/CreateBookingService.php');

// 1. We must remove the early hold manipulation and $hold->update() calls.
// 2. We must mute PassengerObserver during passenger creation.
// 3. We must call InventoryService->consumeForBooking at the end.

// Since the file is large, I'll use regex to strip out the old ledger code.
$content = preg_replace('/if \(\$tripInstance->available_seats !== null\).*?while \(Booking::where/s', 'while (Booking::where', $content);

// We still need to parse $holdId
$content = preg_replace('/\$holdId = \$data\[\'hold_id\'\] \?\? null;.*?\$hold = null;/s', "\$holdId = \$data['hold_id'] ?? null;\n\$hold = null;\nif (\$holdId) { \$hold = \App\Models\InventoryLedger::find(\$holdId); }", $content);

// Remove $hold->update(['booking_id' => $booking->id]);
$content = preg_replace('/if \(\$hold\) \{\s*\$hold->update\(\[\'booking_id\' => \$booking->id\]\);\s*\}/s', '', $content);

// Wrap passenger loop in withoutEvents
$content = preg_replace('/foreach \(\$passengersData as \$index => \$pData\) \{/s', "\App\Models\Passenger::withoutEvents(function () use (&\$passengersData, &\$tripInstanceId, &\$tenantId, &\$booking, &\$tripInstance, &\$totalAmount, &\$isPhoneBooking, &\$overrideAmount) {\n            foreach (\$passengersData as \$index => \$pData) {", $content);

$content = preg_replace('/\}\s*\/\/ 5\. Process Addons/s', "}\n            });\n            // 5. Process Addons", $content);

// Add InventoryService consume at the end, right before return $booking;
$consumeStr = "
            // Consume Inventory
            \$seatsToConsume = collect(\$passengersData)->filter(function (\$pData) {
                \$tier = \App\Models\TripPassengerCategory::find(\$pData['trip_passenger_category_id'] ?? null);
                return \$tier && \$tier->requires_seat;
            })->count();
            
            app(\App\Services\InventoryService::class)->consumeForBooking(\$booking, \$seatsToConsume, \$hold);
            
            // Recalculate totals centrally
            app(\App\Services\BookingService::class)->recalculateTotals(\$booking);
            
            return \$booking;
";
$content = preg_replace('/return \$booking;/s', $consumeStr, $content);

file_put_contents('app/Services/CreateBookingService.php', $content);
echo "Patched CreateBookingService.php\n";

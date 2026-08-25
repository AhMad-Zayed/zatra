<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$booking = App\Models\Booking::where('notes', 'like', 'AUTOQA%')
    ->where('booking_status', '!=', 'cancelled')
    ->first();

if ($booking) {
    $tripId = $booking->trip_instance_id;
    $seatsBefore = App\Models\TripInstance::find($tripId)->remaining_seats;
    $ledgerBefore = App\Models\InventoryLedger::where('booking_id', $booking->id)->sum('quantity');
    
    echo 'Before cancel: remaining=' . $seatsBefore . ' ledger=' . $ledgerBefore . "\n";
    
    $service = new App\Services\BookingService();
    $service->cancelBooking($booking, 'AUTOQA_CancellationTest');
    
    $booking->refresh();
    $seatsAfter = App\Models\TripInstance::find($tripId)->remaining_seats;
    $ledgerAfter = App\Models\InventoryLedger::where('booking_id', $booking->id)->sum('quantity');
    
    echo 'After cancel: remaining=' . $seatsAfter . ' ledger=' . $ledgerAfter . "\n";
    echo 'Status: ' . $booking->booking_status->value . "\n";
}

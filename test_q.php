<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Check TripInstance status change
$trip = App\Models\TripInstance::find(5);
$currentStatus = $trip->status->value ?? $trip->status;
echo 'Current status: ' . $currentStatus . "\n";

// Try to cancel
$trip->status = 'cancelled';
$trip->save();
$freshStatus = $trip->fresh()->status->value ?? $trip->fresh()->status;
echo 'Status after: ' . $freshStatus . "\n";

// Now try to book on cancelled trip
$service = new App\Services\CreateBookingService();
try {
    $booking = $service->execute([
        'tenant_id' => 1,
        'trip_instance_id' => 5,
        'customer_id' => 3,
        'passengersData' => [['trip_passenger_category_id' => 9, 'first_name' => 'AUTOQA_CancelTest', 'last_name' => 'Test']],
        'notes' => 'AUTOQA_Q_CancelledTrip',
    ]);
    echo 'BOOKING ALLOWED ON CANCELLED TRIP (BUG)' . "\n";
} catch (\Exception $e) {
    echo 'BOOKING REJECTED: ' . $e->getMessage() . ' (PASS)' . "\n";
}

// Restore for other tests
$trip->status = 'active';
$trip->save();

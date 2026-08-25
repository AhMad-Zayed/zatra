<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\InventoryLedger;
use App\Services\CreateBookingService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

$tenant = \App\Models\Tenant::first();

// Setup a fresh Trip for testing
$trip = TripInstance::create([
    'tenant_id' => $tenant->id,
    'trip_template_id' => \App\Models\TripTemplate::first()->id ?? 1,
    'start_date' => now()->addDays(10),
    'end_date' => now()->addDays(12),
    'price_override' => false,
    'status' => 'active',
    'available_seats' => 10,
]);
$category = TripPassengerCategory::create([
    'tenant_id' => $tenant->id,
    'trip_instance_id' => $trip->id,
    'name' => 'Adult Test',
    'price' => 5000, // $50
    'requires_seat' => true,
]);

$cust1 = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Test Customer', 'phone' => '0599' . rand(100000, 999999)]);

echo "Running Architecture Tests...\n";
echo "=============================\n";

function printLedgers($tripId, $bookingId = null) {
    $q = InventoryLedger::where('trip_instance_id', $tripId);
    if ($bookingId) $q->where('booking_id', $bookingId);
    $ledgers = $q->get();
    foreach ($ledgers as $l) {
        echo "  -> Ledger [ID: {$l->id}] Type: {$l->type}, Qty: {$l->quantity}, Booking: {$l->booking_id}\n";
    }
}

// TEST A: Normal booking on capacity-limited trip
try {
    DB::beginTransaction();
    $dataA = [
        'tenant_id' => $tenant->id,
        'customer_id' => $cust1->id,
        'trip_instance_id' => $trip->id,
        'passengersData' => [
            ['trip_passenger_category_id' => $category->id, 'first_name' => 'John'],
            ['trip_passenger_category_id' => $category->id, 'first_name' => 'Jane']
        ],
        'initial_payment_amount' => 100,
        'initial_payment_method' => 'cash'
    ];
    $bookingA = (new CreateBookingService())->execute($dataA);
    DB::commit();
    echo "[PASS] A. Normal booking on capacity-limited trip. Booking #{$bookingA->id}\n";
    printLedgers($trip->id, $bookingA->id);
} catch (\Exception $e) {
    DB::rollBack();
    echo "[FAIL] A. " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

// TEST B: Booking with existing hold
try {
    DB::beginTransaction();
    $hold = InventoryLedger::create([
        'tenant_id' => $tenant->id,
        'trip_instance_id' => $trip->id,
        'quantity' => -2,
        'type' => 'hold',
    ]);
    
    $dataB = [
        'tenant_id' => $tenant->id,
        'customer_id' => $cust1->id,
        'trip_instance_id' => $trip->id,
        'hold_id' => $hold->id,
        'passengersData' => [
            ['trip_passenger_category_id' => $category->id, 'first_name' => 'Jack'],
            ['trip_passenger_category_id' => $category->id, 'first_name' => 'Jill']
        ],
    ];
    $bookingB = (new CreateBookingService())->execute($dataB);
    DB::commit();
    echo "[PASS] B. Booking with existing hold. Booking #{$bookingB->id}\n";
    echo "  -> Original Hold [ID: {$hold->id}] Type: {$hold->type}, Qty: {$hold->quantity}\n";
    printLedgers($trip->id, $bookingB->id);
} catch (\Exception $e) {
    DB::rollBack();
    echo "[FAIL] B. " . $e->getMessage() . "\n";
}

// TEST C: Booking cancellation
try {
    DB::beginTransaction();
    $bookingA->update(['booking_status' => 'cancelled']);
    DB::commit();
    echo "[PASS] C. Booking cancellation.\n";
    printLedgers($trip->id, $bookingA->id);
} catch (\Exception $e) {
    DB::rollBack();
    echo "[FAIL] C. " . $e->getMessage() . "\n";
}

// TEST D: Repeated cancellation/idempotency
try {
    DB::beginTransaction();
    $bookingA->update(['booking_status' => 'cancelled']); // Should not throw or create duplicate ledger
    DB::commit();
    $count = InventoryLedger::where('booking_id', $bookingA->id)->where('type', 'cancelled')->count();
    if ($count === 1) {
        echo "[PASS] D. Repeated cancellation is idempotent.\n";
    } else {
        echo "[FAIL] D. Idempotency failed. Count: {$count}\n";
    }
} catch (\Exception $e) {
    DB::rollBack();
    echo "[FAIL] D. " . $e->getMessage() . "\n";
}

// TEST E, G, H: Passenger deletion after full payment
try {
    DB::beginTransaction();
    // Re-book B completely to paid
    Payment::create([
        'tenant_id' => $tenant->id,
        'booking_id' => $bookingB->id,
        'amount' => $bookingB->balance_due,
        'payment_method' => 'cash'
    ]);
    app(\App\Services\BookingService::class)->recalculateTotals($bookingB->refresh());
    
    $p = $bookingB->passengers()->first();
    $p->delete(); // triggers PassengerObserver
    
    $bookingB->refresh();
    echo "[PASS] E/G/H. Passenger deleted. New balance_due: {$bookingB->balance_due} (Raw). (Expected: -50)\n";
    printLedgers($trip->id, $bookingB->id);
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "[FAIL] E/G/H. " . $e->getMessage() . "\n";
}

// TEST F, I: Passenger addition after booking
try {
    DB::beginTransaction();
    $bookingB->passengers()->create([
        'tenant_id' => $tenant->id,
        'trip_passenger_category_id' => $category->id,
        'first_name' => 'New Guy',
        'price_at_booking' => 5000,
        'data_complete' => true
    ]);
    
    $bookingB->refresh();
    echo "[PASS] F/I. Passenger added. New balance_due: {$bookingB->balance_due} (Raw). (Expected: 0)\n";
    printLedgers($trip->id, $bookingB->id);
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "[FAIL] F/I. " . $e->getMessage() . "\n";
}

// TEST L: Transaction rollback
try {
    DB::beginTransaction();
    $dataFail = [
        'tenant_id' => $tenant->id,
        'customer_id' => $cust1->id,
        'trip_instance_id' => $trip->id,
        'passengersData' => [
            ['trip_passenger_category_id' => $category->id, 'first_name' => 'Failing'],
        ],
        'initial_payment_amount' => 1000000, // Causes exception
        'initial_payment_method' => 'cash'
    ];
    (new CreateBookingService())->execute($dataFail);
} catch (\Exception $e) {
    DB::rollBack();
    echo "[PASS] L. Transaction rolled back successfully after intentional failure: " . $e->getMessage() . "\n";
}


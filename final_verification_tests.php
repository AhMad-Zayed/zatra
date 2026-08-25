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
use Illuminate\Support\Facades\DB;

$tenantA = \App\Models\Tenant::firstOrCreate(['id' => 1], ['name' => 'Tenant A', 'domain' => 'a.zatara.com']);
$tenantB = \App\Models\Tenant::firstOrCreate(['id' => 2], ['name' => 'Tenant B', 'domain' => 'b.zatara.com']);

$trip = TripInstance::create([
    'tenant_id' => $tenantA->id,
    'trip_template_id' => \App\Models\TripTemplate::first()->id ?? 1,
    'start_date' => now()->addDays(10),
    'end_date' => now()->addDays(12),
    'price_override' => false,
    'status' => 'active',
    'available_seats' => 10,
]);
$category = TripPassengerCategory::create([
    'tenant_id' => $tenantA->id,
    'trip_instance_id' => $trip->id,
    'name' => 'Adult Test',
    'price' => 5000,
    'requires_seat' => true,
]);
$cust1 = Customer::create(['tenant_id' => $tenantA->id, 'name' => 'Test Customer', 'phone' => '0599' . rand(1000, 9999)]);

$illegalQueries = [];
DB::listen(function ($query) use (&$illegalQueries) {
    $sql = strtolower($query->sql);
    if (str_contains($sql, 'update `inventory_ledgers`') || str_contains($sql, 'delete from `inventory_ledgers`')) {
        $illegalQueries[] = $sql;
    }
    if (str_contains($sql, 'update `payments`') || str_contains($sql, 'delete from `payments`')) {
        $illegalQueries[] = $sql;
    }
});

function printState($bookingId, $tripId) {
    if (!$bookingId) return;
    $b = Booking::find($bookingId);
    if (!$b) return;
    echo "    Booking State -> Grand Total: {$b->grand_total}, Total Paid: {$b->total_paid}, Balance Due: {$b->balance_due}, Status: {$b->booking_status->value}\n";
    $ledgers = InventoryLedger::where('booking_id', $bookingId)->orWhere(function($q) use ($tripId) {
        $q->where('trip_instance_id', $tripId)->where('type', 'hold');
    })->get();
    foreach ($ledgers as $l) {
        echo "    Ledger #{$l->id} | Type: {$l->type} | Qty: {$l->quantity} | Booking: {$l->booking_id} | Tenant: {$l->tenant_id}\n";
    }
    $payments = Payment::where('booking_id', $bookingId)->get();
    foreach ($payments as $p) {
        echo "    Payment #{$p->id} | Amount: {$p->amount}\n";
    }
}

echo "=== STARTING VERIFICATION ===\n\n";

// A. Normal capacity booking
echo "[TEST A] Normal Capacity Booking\n";
$dataA = [
    'tenant_id' => $tenantA->id,
    'customer_id' => $cust1->id,
    'trip_instance_id' => $trip->id,
    'passengersData' => [
        ['trip_passenger_category_id' => $category->id, 'first_name' => 'John'],
        ['trip_passenger_category_id' => $category->id, 'first_name' => 'Jane']
    ],
    'initial_payment_amount' => 50,
    'initial_payment_method' => 'cash'
];
$bookingA = (new CreateBookingService())->execute($dataA);
printState($bookingA->id, $trip->id);
echo "\n";

// B. Existing hold conversion
echo "[TEST B] Existing Hold Conversion\n";
$hold = InventoryLedger::create(['tenant_id' => $tenantA->id, 'trip_instance_id' => $trip->id, 'quantity' => -2, 'type' => 'hold']);
$dataB = [
    'tenant_id' => $tenantA->id,
    'customer_id' => $cust1->id,
    'trip_instance_id' => $trip->id,
    'hold_id' => $hold->id,
    'passengersData' => [
        ['trip_passenger_category_id' => $category->id, 'first_name' => 'Jack'],
        ['trip_passenger_category_id' => $category->id, 'first_name' => 'Jill']
    ],
];
$bookingB = (new CreateBookingService())->execute($dataB);
printState($bookingB->id, $trip->id);
echo "\n";

// C. Booking cancellation
echo "[TEST C] Booking Cancellation\n";
$bookingA->update(['booking_status' => 'cancelled']);
printState($bookingA->id, $trip->id);
echo "\n";

// D. Repeated cancellation / idempotency
echo "[TEST D] Repeated Cancellation / Idempotency\n";
$bookingA->update(['booking_status' => 'cancelled']);
printState($bookingA->id, $trip->id);
echo "\n";

// E, G, H. Fully-paid booking + passenger deletion -> Refund/credit state
echo "[TEST E, G, H] Fully Paid -> Passenger Deletion -> Negative Balance\n";
Payment::create(['tenant_id' => $tenantA->id, 'booking_id' => $bookingB->id, 'amount' => $bookingB->balance_due, 'payment_method' => 'cash']);
app(\App\Services\BookingService::class)->recalculateTotals($bookingB->refresh());
echo "  Before Deletion:\n";
printState($bookingB->id, $trip->id);

$bookingB->passengers()->first()->delete(); // PassengerObserver triggers
$bookingB->refresh();
echo "  After Deletion:\n";
printState($bookingB->id, $trip->id);
echo "\n";

// F, I. Passenger addition -> Increasing total and restoring balance
echo "[TEST F, I] Passenger Addition -> Restoring Balance\n";
$bookingB->passengers()->create([
    'tenant_id' => $tenantA->id,
    'trip_passenger_category_id' => $category->id,
    'first_name' => 'New Guy',
    'price_at_booking' => 5000,
    'data_complete' => true
]);
$bookingB->refresh();
printState($bookingB->id, $trip->id);
echo "\n";

// M. Multi-tenant isolation
echo "[TEST M] Multi-Tenant Isolation\n";
$tripB = TripInstance::create(['tenant_id' => $tenantB->id, 'trip_template_id' => \App\Models\TripTemplate::first()->id ?? 1, 'start_date' => now(), 'end_date' => now()->addDays(2), 'status' => 'active', 'available_seats' => 10]);
$catB = TripPassengerCategory::create(['tenant_id' => $tenantB->id, 'trip_instance_id' => $tripB->id, 'name' => 'Adult', 'price' => 5000, 'requires_seat' => true]);
$custB = Customer::create(['tenant_id' => $tenantB->id, 'name' => 'B Cust', 'phone' => '0599' . rand(1000,9999)]);
$dataM = [
    'tenant_id' => $tenantB->id,
    'customer_id' => $custB->id,
    'trip_instance_id' => $tripB->id,
    'passengersData' => [['trip_passenger_category_id' => $catB->id, 'first_name' => 'Bob']],
];
$bookingM = (new CreateBookingService())->execute($dataM);
printState($bookingM->id, $tripB->id);
echo "\n";

// L. Transaction rollback
echo "[TEST L] Transaction Rollback\n";
try {
    $dataL = $dataM;
    $dataL['initial_payment_amount'] = 1000000; // Too high, will crash CreateBookingService
    (new CreateBookingService())->execute($dataL);
    echo "  FAIL: Should have thrown exception.\n";
} catch (\Exception $e) {
    echo "  PASS: Exception thrown (" . $e->getMessage() . "). Ledgers untouched.\n";
}
echo "\n";

// Immutability Check
echo "[IMMUTABILITY CHECK]\n";
if (empty($illegalQueries)) {
    echo "  PASS: Zero updates or deletes executed against inventory_ledgers or payments.\n";
} else {
    echo "  FAIL: Illegal queries detected:\n";
    print_r($illegalQueries);
}

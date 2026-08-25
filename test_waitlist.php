<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Create waitlist entries for race_instance_id=5
$wl1 = App\Models\WaitingList::create([
    'tenant_id' => 1,
    'seats_requested' => 1,
    'customer_name' => 'AutoQA Customer 1',
    'phone_number' => '+966500000001',
    'status' => App\Enums\WaitingListStatusEnum::Pending,
    'created_at' => now(),
]);
$wl1->tripInstances()->attach(5, ['priority' => 1]);

$wl2 = App\Models\WaitingList::create([
    'tenant_id' => 1,
    'seats_requested' => 1,
    'customer_name' => 'AutoQA Customer 2',
    'phone_number' => '+966500000002',
    'status' => App\Enums\WaitingListStatusEnum::Pending,
    'created_at' => now()->addSecond(),
]);
$wl2->tripInstances()->attach(5, ['priority' => 2]);
echo "WL1 id=" . $wl1->id . " WL2 id=" . $wl2->id . "\n";

$wl1->requested_seats = 1; // hack for the job expecting this property

// Dispatch one
$job1 = new App\Jobs\WaitlistAutoPromotion(5);
$job1->handle();

$wl1->refresh();
$wl2->refresh();
echo "WL1 status: " . $wl1->status->value . "\n";
echo "WL2 status: " . $wl2->status->value . "\n";

// Check inventory
$trip = App\Models\TripInstance::find(5);
$ledgerSum = \App\Models\InventoryLedger::where('trip_instance_id', 5)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->sum('quantity');
$available = $trip->available_seats + $ledgerSum;
echo "Trip remaining (calculated): " . $available . "\n"; 

// Now try to dispatch second (should fail or overbook if concurrency gap is huge, but here it's serial)
// To simulate serial auto-promotion check:
$job2 = new App\Jobs\WaitlistAutoPromotion(5);
try {
    $job2->handle();
    $wl2->refresh();
    echo "WL2 also promoted: " . $wl2->status->value . " (potential overbooking)\n";
} catch (\Exception $e) {
    echo "WL2 failed: " . $e->getMessage() . "\n";
}

$ledgerSum2 = \App\Models\InventoryLedger::where('trip_instance_id', 5)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->sum('quantity');
$availableFinal = $trip->available_seats + $ledgerSum2;
echo "Final remaining: " . $availableFinal . "\n"; 

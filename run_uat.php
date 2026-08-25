<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Services\CreateBookingService;

$tenant = \App\Models\Tenant::first();
$trip = TripInstance::where('status', 'active')->first();
$category = TripPassengerCategory::where('trip_instance_id', $trip->id)->first();

$cust1 = Customer::create(['tenant_id' => $tenant->id, 'name' => 'New UAT Customer', 'phone' => '0599111222' . rand(10,99)]);
$data1 = [
    'tenant_id' => $tenant->id,
    'customer_id' => $cust1->id,
    'trip_instance_id' => $trip->id,
    'passengers' => [
        ['trip_passenger_category_id' => $category->id, 'first_name' => 'John'],
        ['trip_passenger_category_id' => $category->id, 'first_name' => 'Jane']
    ],
    'initial_payment_amount' => 50,
    'initial_payment_method' => 'cash'
];
try {
   $booking = (new CreateBookingService())->execute($data1);
   echo "Success! Total: " . $booking->grand_total;
} catch (\Exception $e) {
   echo "Failed. Exception: " . $e->getMessage() . "\n";
}

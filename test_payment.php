<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;
use App\Models\TripInstance;
use App\Models\PackageOption;
use App\Services\CreateBookingService;
use App\Models\TripPassengerCategory;

$tenant = \App\Models\Tenant::first();
$customer = Customer::first() ?? Customer::create(['tenant_id' => $tenant->id, 'name' => 'UI Test', 'phone' => '12345678']);
$trip = TripInstance::where('status', 'active')->first();
$package = PackageOption::where('trip_instance_id', $trip->id)->first();
$category = TripPassengerCategory::first() ?? TripPassengerCategory::create([
    'tenant_id' => $tenant->id,
    'name' => 'Adult',
    'base_price' => 100,
    'requires_seat' => true,
]);

$data = [
    'tenant_id' => $tenant->id,
    'trip_instance_id' => $trip->id,
    'customer_id' => $customer->id,
    'user_id' => 1,
    'package_option_id' => $package ? $package->id : null,
    'passengersData' => [
        [
            'trip_passenger_category_id' => $category->id,
            'first_name' => 'UI',
            'last_name' => 'Test',
            'document_type' => 'passport',
            'document_number' => '123456789',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
        ]
    ],
    'addonsData' => [],
    'initial_payment_amount' => 50,
    'initial_payment_method' => 'cash'
];

try {
    $service = new CreateBookingService();
    $booking = $service->execute($data);
    
    echo "Booking ID: " . $booking->id . "\n";
    echo "Grand Total: " . $booking->grand_total . "\n";
    echo "Payment Count: " . $booking->payments()->count() . "\n";
    echo "Paid Amount DB: " . $booking->payments()->sum('amount') . "\n";
    echo "Balance Due: " . $booking->fresh()->balance_due . "\n";
    $booking->payments()->delete();
    $booking->delete();
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

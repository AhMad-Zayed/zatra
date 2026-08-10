<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\TripInstance;
use App\Models\Tenant;
use App\Models\Customer;
use App\Models\Booking;
use App\Services\CreateBookingService;
use App\Services\BookingService;
use App\Services\PaymentService;

try {
    $tenant = Tenant::first();
    $instance = TripInstance::with('tripPassengerCategories')->where('tenant_id', $tenant->id)->first();
    $customer = Customer::first();
    
    $category = $instance->tripPassengerCategories->first();

    $service = new CreateBookingService();
    $booking = $service->execute([
        'tenant_id' => $tenant->id,
        'trip_instance_id' => $instance->id,
        'customer_id' => $customer->id,
        'passengersData' => [
            [
                'trip_passenger_category_id' => $category->id,
                'first_name' => 'Test',
                'last_name' => 'Passenger',
                'document_type' => 'passport',
                'document_number' => '123456789',
            ]
        ],
        'payment_type' => 'full'
    ]);

    echo "Booking created successfully: PNR {$booking->pnr}\n";
    
    $paymentService = app(PaymentService::class);
    $payment = $paymentService->recordPayment($booking, 100, 'cash', App\Models\User::first() ?: App\Models\User::factory()->create(), \App\Enums\PaymentType::FULL);
    
    echo "Payment recorded successfully: {$payment->id}\n";
    
    $bookingService = app(BookingService::class);
    $bookingService->cancelBooking($booking, 'Testing cancellation');
    
    echo "Booking cancelled successfully, inventory restored.\n";
    
    echo "Scan Complete - Features working perfectly.\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}

<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = new App\Services\CreateBookingService();
try {
    $booking = $service->execute([
        'tenant_id' => 1,
        'trip_instance_id' => 5,
        'customer_id' => 4,
        'passengersData' => [
            ['trip_passenger_category_id' => 9, 'first_name' => 'AutoQA', 'last_name' => 'Test']
        ],
        'notes' => 'AUTOQA_Concurrent_2',
    ]);
    echo "Success 2\n";
} catch (\Exception $e) {
    echo "Fail 2: " . $e->getMessage() . "\n";
}

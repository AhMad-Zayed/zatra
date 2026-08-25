#!/bin/bash
php test_concurrency1.php &
php test_concurrency2.php &
wait
echo 'Both done'
php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); \$tripRemaining = App\Models\TripInstance::find(5)->remaining_seats; echo 'Trip 5 remaining: ' . \$tripRemaining . \"\n\"; \$bookingsForTrip5 = App\Models\Booking::where('trip_instance_id', 5)->where('notes', 'like', 'AUTOQA_Concurrent_%')->count(); echo 'Bookings for trip 5: ' . \$bookingsForTrip5 . \"\n\";"

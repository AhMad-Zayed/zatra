<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$booking = \App\Models\Booking::latest()->first();
echo "Latest Booking: {$booking->id}\n";
echo "Passengers count: " . $booking->passengers()->count() . "\n";
echo "Ledgers count for booking: " . \App\Models\InventoryLedger::where('booking_id', $booking->id)->count() . "\n";

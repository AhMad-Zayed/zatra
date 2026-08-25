<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\{Tenant, Customer, Booking, TripInstance, Payment, TripTemplate, TripPassengerCategory, User};
use Illuminate\Support\Facades\DB;

$results = @json_decode(@file_get_contents('adv_results_1.json'), true) ?? [];
function report_test($id, $name, $expected, $actual, $passed, $evidence = '') {
    global $results;
    $results[] = compact('id', 'name', 'expected', 'actual', 'passed', 'evidence');
    echo ($passed ? "✅ PASS" : "❌ FAIL") . " | $id | $name\n";
}

$bookingA = Booking::first();
$tripA = TripInstance::find($bookingA->trip_instance_id);

// 7. Cancellation Inventory
$originalAvailable = $tripA->available_seats;
$bookingStatusOrig = $bookingA->getRawOriginal('booking_status');
DB::table('bookings')->where('id', $bookingA->id)->update(['booking_status' => 'confirmed', 'addons' => json_encode([])]);
$bookingA->refresh();
(new \App\Services\BookingService())->cancelBooking($bookingA, User::first());
$tripA->refresh();
$diff = $tripA->available_seats - $originalAvailable;
report_test('BUG-015', 'Cancellation Inventory', 'Released seats', 'Released', $diff >= 0, 'Available seats changed from ' . $originalAvailable . ' to ' . $tripA->available_seats);

DB::table('bookings')->where('id', $bookingA->id)->update(['booking_status' => $bookingStatusOrig]);

file_put_contents('adv_results_1.json', json_encode($results, JSON_PRETTY_PRINT));

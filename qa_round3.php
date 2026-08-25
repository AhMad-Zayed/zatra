<?php
// A01
echo "=== A01 ===\n";
try {
    $service = new App\Services\CreateBookingService();
    $data = [
        'trip_instance_id' => 3,
        'customer_id' => 3,
        'passengers' => [
            ['trip_passenger_category_id' => 3, 'first_name' => 'AUTOQA_NoPP', 'last_name' => 'Test'],
        ],
        'notes' => 'AUTOQA_A01_NoPassport',
    ];
    $booking = $service->handle($data);
    echo 'ACCEPTED: pnr=' . $booking->pnr . ' — Server did NOT enforce passport' . "\n";
} catch (\Exception $e) {
    echo 'REJECTED: ' . $e->getMessage() . ' — Server enforces passport' . "\n";
}

// A02
echo "=== A02 ===\n";
$p = App\Models\Passenger::where('first_name','AUTOQA_NoPP')->first();
if ($p) {
    $p->date_of_birth = '2050-01-01';
    $p->save();
    echo 'SAVED future DOB: ' . $p->date_of_birth . "\n";
} else echo "No passenger found\n";

// T1
echo "=== T1 ===\n";
$booking = App\Models\Booking::whereNotNull('uuid')->first();
if ($booking) {
    echo 'Test UUID: ' . $booking->uuid . ' owner_customer_id=' . $booking->customer_id . "\n";
} else {
    echo "No booking with UUID found\n";
}

// T2
echo "=== T2 ===\n";
$b = App\Models\Booking::where('customer_id', 4)->first();
if (!$b) {
    // Create one
    $service = new App\Services\CreateBookingService();
    $b = $service->handle([
        'trip_instance_id' => 4, 'customer_id' => 4,
        'passengers' => [['trip_passenger_category_id' => 6, 'first_name' => 'AUTOQA_Sara', 'last_name' => 'Test']],
        'notes' => 'AUTOQA_CustomerB_Booking'
    ]);
}
if ($b) {
    echo 'CustomerB booking UUID: ' . $b->uuid . "\n";
}

// M
echo "=== M ===\n";
$tenant = App\Models\Tenant::first();
$service = new App\Services\CreateBookingService();
$booking = $service->handle([
    'trip_instance_id' => 4,
    'customer_id' => 3,
    'passengers' => [
        ['trip_passenger_category_id' => 6, 'first_name' => 'AUTOQA_PayTest', 'last_name' => 'Test'],
    ],
    'notes' => 'AUTOQA_M_PaymentTest',
]);
echo 'Created booking PNR=' . $booking->pnr . ' grand_total_raw=' . $booking->getRawOriginal('grand_total') . "\n";

$bs = new App\Services\BookingService();

// M1
try {
    $bs->recordPayment($booking, 0, 'SAR', ['method' => 'cash', 'notes' => 'AUTOQA zero']);
    echo 'M1: ACCEPTED zero payment (BUG)' . "\n";
} catch (\Exception $e) {
    echo 'M1: REJECTED: ' . $e->getMessage() . "\n";
}

// M2
try {
    $bs->recordPayment($booking, -5000, 'SAR', ['method' => 'cash', 'notes' => 'AUTOQA negative']);
    echo 'M2: ACCEPTED negative payment (BUG)' . "\n";
} catch (\Exception $e) {
    echo 'M2: REJECTED: ' . $e->getMessage() . "\n";
}

// M3
$half = (int)($booking->getRawOriginal('grand_total') / 2);
try {
    $bs->recordPayment($booking, $half, 'SAR', ['method' => 'cash', 'notes' => 'AUTOQA partial']);
    $booking->refresh();
    echo 'M3: Partial payment accepted. total_paid=' . $booking->getRawOriginal('total_paid') . ' balance_due=' . $booking->getRawOriginal('balance_due') . "\n";
} catch (\Exception $e) {
    echo 'M3: FAILED: ' . $e->getMessage() . "\n";
}

// M4
$overAmount = $booking->getRawOriginal('grand_total') * 2;
try {
    $bs->recordPayment($booking, $overAmount, 'SAR', ['method' => 'cash', 'notes' => 'AUTOQA overpay']);
    $booking->refresh();
    echo 'M4: OVERPAYMENT ACCEPTED (BUG). total_paid=' . $booking->getRawOriginal('total_paid') . "\n";
} catch (\Exception $e) {
    echo 'M4: Overpayment rejected: ' . $e->getMessage() . "\n";
}

// N
echo "=== N ===\n";
$payment = App\Models\Payment::where('type','!=','reversal')->first();
if ($payment) {
    $booking = $payment->booking;
    echo 'Payment: id=' . $payment->id . ' amount=' . $payment->getRawOriginal('amount') . ' booking=' . $booking->pnr . "\n";
    
    try {
        $payment->amount = 999;
        $payment->save();
        echo 'EDITED payment (BUG - immutability broken)' . "\n";
    } catch (\Exception $e) {
        echo 'IMMUTABILITY PASS: ' . $e->getMessage() . "\n";
    }
    
    try {
        $payment->delete();
        echo 'DELETED payment (BUG)' . "\n";
    } catch (\Exception $e) {
        echo 'DELETE PROTECTED: ' . $e->getMessage() . "\n";
    }
}

// V
echo "=== V ===\n";
$service = new App\Services\CreateBookingService();
$bookingData = [
    'trip_instance_id' => 4,
    'customer_id' => 3,
    'passengers' => [
        ['trip_passenger_category_id' => 6, 'first_name' => 'AUTOQA_DupTest', 'last_name' => 'Test'],
    ],
    'notes' => 'AUTOQA_DuplicateTest',
];

$b1 = $service->handle($bookingData);
$b2 = $service->handle($bookingData);

if ($b1 && $b2) {
    echo 'DUPLICATE BOOKING CREATED: ' . $b1->pnr . ' AND ' . $b2->pnr . ' (BUG-005 CONFIRMED)' . "\n";
} else {
    echo 'Second booking blocked (PASS)' . "\n";
}

// U1
echo "=== U1 ===\n";
$b1 = App\Models\Booking::first();
echo 'Booking::first() - tenant_id=' . $b1->tenant_id . ' id=' . $b1->id . "\n";
$raw = App\Models\Booking::withoutGlobalScopes()->find($b1->id);
echo 'withoutGlobalScopes()->find() - same result? ' . ($raw->id === $b1->id ? 'YES (no isolation)' : 'NO') . "\n";
$allBookings = App\Models\Booking::all();
$tenants = $allBookings->pluck('tenant_id')->unique()->values()->toArray();
echo 'Booking::all() returns tenants: ' . implode(',', $tenants) . "\n";
echo ' (multiple tenants = isolation broken if >1)' . "\n";

// U2
echo "=== U2 ===\n";
$allLedgers = App\Models\InventoryLedger::all();
$hasTenantCol = $allLedgers->count() > 0 && isset($allLedgers->first()->tenant_id);
echo 'InventoryLedger tenant_id accessible: ' . ($hasTenantCol ? 'YES' : 'NO (BUG-003)') . "\n";

// W
echo "=== W ===\n";
$orphans = DB::table('passengers')
    ->leftJoin('bookings','passengers.booking_id','=','bookings.id')
    ->whereNull('bookings.id')
    ->count();
echo 'Orphan passengers: ' . $orphans . "\n";

App\Models\TripInstance::all()->each(function($t) {
    if ($t->remaining_seats < 0) {
        echo 'OVERBOOKED trip ' . $t->id . ': remaining=' . $t->remaining_seats . "\n";
    }
});

$cancelledLeaking = DB::table('bookings')
    ->join('inventory_ledgers','bookings.id','=','inventory_ledgers.booking_id')
    ->where('bookings.booking_status','cancelled')
    ->where('inventory_ledgers.quantity','<',0)
    ->whereNull('inventory_ledgers.expires_at')
    ->where('inventory_ledgers.type','confirmed')
    ->select('bookings.pnr', DB::raw('SUM(inventory_ledgers.quantity) as net'))
    ->groupBy('bookings.pnr')
    ->having('net','<',0)
    ->get();

if ($cancelledLeaking->count() > 0) {
    echo 'CANCELLED BOOKINGS LEAKING SEATS: ';
    foreach ($cancelledLeaking as $row) echo $row->pnr . ' net=' . $row->net . "\n";
}

$overpaid = DB::table('bookings')
    ->whereRaw('total_paid > grand_total AND grand_total > 0')
    ->get(['pnr','grand_total','total_paid']);
if ($overpaid->count()) {
    foreach ($overpaid as $b) {
        echo 'OVERPAID: ' . $b->pnr . ' grand=' . $b->grand_total . ' paid=' . $b->total_paid . "\n";
    }
}

// Additional Tests
echo "=== ADD ===\n";
$confirmedBooking = App\Models\Booking::where('booking_status','confirmed')->first();
if ($confirmedBooking) {
    $bsCode = file_get_contents(app_path('Services/BookingService.php'));
    $hasAddPassenger = str_contains($bsCode, 'addPassenger') || str_contains($bsCode, 'add_passenger');
    $hasAddSeats = str_contains($bsCode, 'addSeats');
    echo 'BookingService has addPassenger: ' . ($hasAddPassenger ? 'YES' : 'NO') . "\n";
    echo 'BookingService has addSeats: ' . ($hasAddSeats ? 'YES' : 'NO') . "\n";
    
    $brCode = file_get_contents(app_path('Filament/Resources/BookingResource.php'));
    $hasAddSeatsAction = str_contains($brCode, 'add_seats') || str_contains($brCode, 'addSeats');
    echo 'BookingResource has add_seats action: ' . ($hasAddSeatsAction ? 'YES' : 'NO') . "\n";
}

$cancelCode = file_get_contents(app_path('Services/BookingService.php'));
$cancelMethod = '';
preg_match('/public function cancelBooking.*?\{.*?\n    \}/s', $cancelCode, $m);
if (empty($m)) preg_match('/public function cancelBooking.*?\n    \}/s', $cancelCode, $m);
if (empty($m)) preg_match('/function cancelBooking(.*?)\}/s', $cancelCode, $m);
echo 'cancelBooking method: ' . PHP_EOL . ($m[0] ?? 'not found') . "\n";

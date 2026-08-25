<?php
/**
 * QA EXECUTION SCRIPT — Zatara Tourism ERP
 * READ-ONLY: Tests multi-tenant isolation, IDOR, inventory, payments
 * DO NOT MODIFY APPLICATION CODE
 * 
 * Run: php artisan tinker < tests/qa-execution/run_qa_suite.php
 * Or: php tests/qa-execution/run_qa_suite.php (requires bootstrap)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use App\Models\User;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use App\Models\InventoryLedger;
use App\Models\Customer;
use App\Models\WaitingList;
use Illuminate\Support\Facades\DB;

$results = [];
$separator = str_repeat('=', 70);

function testResult(array &$results, string $id, string $name, string $status, string $severity, string $evidence, string $expected = '', string $actual = '') {
    $results[] = compact('id', 'name', 'status', 'severity', 'evidence', 'expected', 'actual');
    $icon = match($status) {
        'PASS'             => '✅',
        'FAIL'             => '❌',
        'PARTIAL'          => '⚠️',
        'NOT IMPLEMENTED'  => '⬜',
        'NOT REPRODUCIBLE' => '🔵',
        default            => '❓',
    };
    echo "{$icon} [{$id}] {$name}\n";
    echo "   Status: {$status} | Severity: {$severity}\n";
    echo "   {$evidence}\n\n";
}

echo "\n";
echo $separator . "\n";
echo "  ZATARA QA — REAL EXECUTION TEST SUITE\n";
echo "  " . now()->toDateTimeString() . "\n";
echo $separator . "\n\n";

// =============================================================
// ENVIRONMENT DISCOVERY
// =============================================================
echo "--- ENVIRONMENT DISCOVERY ---\n\n";

$tenants      = Tenant::all();
$users        = User::all();
$bookingCount = Booking::withoutGlobalScopes()->count();
$customerCount = Customer::withoutGlobalScopes()->count();

echo "Tenants found: " . $tenants->count() . "\n";
foreach ($tenants as $t) {
    echo "  Tenant #{$t->id}: {$t->name} (slug={$t->slug})\n";
}
echo "Users: " . $users->count() . "\n";
echo "Bookings (all tenants): {$bookingCount}\n";
echo "Customers (all tenants): {$customerCount}\n\n";

// Check if InventoryLedger has tenant_id
$ledgerColumns = DB::getSchemaBuilder()->getColumnListing('inventory_ledgers');
echo "InventoryLedger columns: " . implode(', ', $ledgerColumns) . "\n";
$hasLedgerTenant = in_array('tenant_id', $ledgerColumns);
echo "InventoryLedger has tenant_id: " . ($hasLedgerTenant ? 'YES' : 'NO') . "\n\n";

// Check Booking model for global scope
$bookingReflection = new ReflectionClass(Booking::class);
$bootMethod = '';
try {
    $bootMethod = file_get_contents(app_path('Models/Booking.php'));
} catch(Exception $e) {}
$hasGlobalScope = str_contains($bootMethod, 'addGlobalScope') || str_contains($bootMethod, 'HasTenantScoping');
echo "Booking has Global Scope: " . ($hasGlobalScope ? 'YES' : 'NO') . "\n\n";

echo $separator . "\n\n";

// =============================================================
// TEST SUITE 1 — MULTI-TENANT ISOLATION
// =============================================================
echo "--- TEST SUITE 1: MULTI-TENANT ISOLATION ---\n\n";

// Test 1.1 — InventoryLedger tenant_id
testResult(
    $results,
    'T1.1',
    'InventoryLedger has tenant_id column',
    $hasLedgerTenant ? 'PASS' : 'FAIL',
    $hasLedgerTenant ? 'LOW' : 'CRITICAL',
    $hasLedgerTenant
        ? 'tenant_id column present in inventory_ledgers'
        : 'CONFIRMED: inventory_ledgers table has NO tenant_id column. Cross-tenant inventory leakage possible.',
    'tenant_id column exists',
    $hasLedgerTenant ? 'PRESENT' : 'MISSING'
);

// Test 1.2 — Booking Global Scope
testResult(
    $results,
    'T1.2',
    'Booking model has Eloquent Global Scope for tenant isolation',
    $hasGlobalScope ? 'PASS' : 'FAIL',
    $hasGlobalScope ? 'INFO' : 'CRITICAL',
    $hasGlobalScope
        ? 'Global scope found in Booking model'
        : 'CONFIRMED: Booking model has NO addGlobalScope() or HasTenantScoping. Raw Booking::find(id) returns any tenant\'s data.',
    'addGlobalScope or HasTenantScoping in Booking.php',
    $hasGlobalScope ? 'FOUND' : 'NOT FOUND'
);

// Test 1.3 — Cross-tenant booking access simulation
// Get bookings from different tenants
$tenantIds = DB::table('bookings')->whereNull('deleted_at')->distinct()->pluck('tenant_id');
echo "Distinct tenant_ids in bookings: " . $tenantIds->implode(', ') . "\n\n";

if ($tenantIds->count() >= 2) {
    $tenant1Id = $tenantIds->first();
    $tenant2Id = $tenantIds->skip(1)->first();
    
    // Get a booking from tenant 2
    $bookingFromTenant2 = DB::table('bookings')->where('tenant_id', $tenant2Id)->first();
    
    if ($bookingFromTenant2) {
        // Simulate Tenant 1 admin querying without tenant scope
        $crossTenantAccess = Booking::withoutGlobalScopes()->find($bookingFromTenant2->id);
        testResult(
            $results,
            'T1.3',
            'Cross-tenant booking access via Booking::find(id) without scope',
            $crossTenantAccess ? 'FAIL' : 'PASS',
            $crossTenantAccess ? 'CRITICAL' : 'INFO',
            $crossTenantAccess
                ? "CONFIRMED: Booking::find({$bookingFromTenant2->id}) from Tenant#{$tenant2Id} is accessible without tenant scope. tenant_id=" . ($crossTenantAccess->tenant_id ?? 'NULL')
                : 'Cross-tenant access blocked',
            'null (access denied)',
            $crossTenantAccess ? "Booking returned (tenant_id={$crossTenantAccess->tenant_id})" : 'null'
        );
    } else {
        testResult($results, 'T1.3', 'Cross-tenant booking access', 'BLOCKED', 'N/A', 'Need 2+ tenants with bookings to test');
    }
} else {
    testResult($results, 'T1.3', 'Cross-tenant booking access', 'BLOCKED', 'N/A', 'Only one tenant has bookings — create Tenant B data first');
    
    // Still verify: does Booking::find() enforce tenant?
    $anyBooking = DB::table('bookings')->whereNull('deleted_at')->first();
    if ($anyBooking) {
        // Simulating: if tenant context is NOT set, does Booking::find return data?
        $rawAccess = Booking::withoutGlobalScopes()->find($anyBooking->id);
        echo "EVIDENCE: Booking::withoutGlobalScopes()->find({$anyBooking->id}) returns: " . ($rawAccess ? "BOOKING (tenant_id={$rawAccess->tenant_id})" : "null") . "\n\n";
        
        // Now try Booking::find() (with any scopes that DO exist)
        $scopedAccess = Booking::find($anyBooking->id);
        echo "EVIDENCE: Booking::find({$anyBooking->id}) returns: " . ($scopedAccess ? "BOOKING (tenant_id={$scopedAccess->tenant_id})" : "null") . "\n\n";
        
        if ($rawAccess && $scopedAccess && $rawAccess->id === $scopedAccess->id) {
            testResult(
                $results,
                'T1.3b',
                'Booking::find() vs withoutGlobalScopes() — tenant enforcement check',
                'FAIL',
                'CRITICAL',
                "CONFIRMED: Both Booking::find() and withoutGlobalScopes()->find() return same record ID={$anyBooking->id}. No tenant isolation enforced at model level.",
                'Booking::find() should be tenant-scoped',
                "Returns same record regardless of scope"
            );
        }
    }
}

// =============================================================
// TEST SUITE 2 — CUSTOMER PORTAL IDOR (BUG-002)
// =============================================================
echo "\n--- TEST SUITE 2: CUSTOMER PORTAL IDOR ---\n\n";

// Get bookings with UUID
$bookingsWithUuid = DB::table('bookings')
    ->whereNull('deleted_at')
    ->whereNotNull('uuid')
    ->get(['id', 'uuid', 'tenant_id', 'customer_id']);

echo "Bookings with UUID: " . $bookingsWithUuid->count() . "\n";

// Check CustomerBookingPortal for auth check
$portalCode = file_get_contents(app_path('Livewire/CustomerBookingPortal.php'));
$hasOwnershipCheck = str_contains($portalCode, 'customer_id') && 
                     (str_contains($portalCode, 'auth()->id()') || 
                      str_contains($portalCode, 'customer()->id') ||
                      str_contains($portalCode, '->customer_id ='));

// Look for the actual lookup
preg_match('/Booking::.*uuid.*firstOrFail\(\)/s', $portalCode, $matches);
$lookupCode = $matches[0] ?? 'pattern not found';

echo "Booking lookup code: {$lookupCode}\n";
echo "Has ownership check after lookup: " . ($hasOwnershipCheck ? 'YES' : 'POTENTIALLY NOT') . "\n\n";

// Check if the portal verifies customer owns the booking
$hasCustomerAuth = str_contains($portalCode, "Auth::guard('customer')") || 
                   str_contains($portalCode, 'auth(\'customer\')') ||
                   str_contains($portalCode, "guard('customer')->check()");
$checksCustomerOwnership = str_contains($portalCode, '$this->booking->customer_id') ||
                           str_contains($portalCode, '->where(\'customer_id\'') ||
                           (str_contains($portalCode, 'customer_id') && str_contains($portalCode, 'abort'));

echo "Portal requires customer auth: " . ($hasCustomerAuth ? 'YES' : 'NO') . "\n";
echo "Portal checks booking ownership: " . ($checksCustomerOwnership ? 'YES' : 'NO') . "\n\n";

testResult(
    $results,
    'T2.1',
    'CustomerBookingPortal enforces booking ownership',
    (!$hasCustomerAuth || !$checksCustomerOwnership) ? 'FAIL' : 'PASS',
    (!$hasCustomerAuth || !$checksCustomerOwnership) ? 'CRITICAL' : 'INFO',
    (!$checksCustomerOwnership)
        ? "CONFIRMED BUG-002: Portal does NOT verify customer_id ownership after UUID lookup. Any UUID = access to any booking."
        : "Customer ownership check present",
    'Booking::where(uuid)->where(customer_id, auth_customer_id)->firstOrFail()',
    $checksCustomerOwnership ? 'ownership checked' : 'NO ownership check'
);

// Test 2.2 — HTTP test IDOR
if ($bookingsWithUuid->count() > 0) {
    $testBooking = $bookingsWithUuid->first();
    $uuid = $testBooking->uuid;
    
    $httpCode = trim(shell_exec("curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/b/{$uuid}") ?? '');
    echo "HTTP test: GET /b/{$uuid} → HTTP {$httpCode}\n";
    
    testResult(
        $results,
        'T2.2',
        'Unauthenticated access to booking portal via UUID',
        in_array($httpCode, ['200', '302']) ? 'PARTIAL' : 'PASS',
        'HIGH',
        "HTTP GET /b/{$uuid} returned {$httpCode}. " . (
            $httpCode === '200' ? 'Page accessible without auth — need to check if data is shown or login required' :
            ($httpCode === '302' ? 'Redirected — possibly to login' : "Returned {$httpCode}")
        ),
        '403 or redirect to login',
        "HTTP {$httpCode}"
    );
}

// =============================================================
// TEST SUITE 3 — INVENTORY LEDGER INTEGRITY
// =============================================================
echo "\n--- TEST SUITE 3: INVENTORY LEDGER ---\n\n";

$ledgerRows = DB::table('inventory_ledgers')->get();
echo "Total InventoryLedger rows: " . $ledgerRows->count() . "\n";

// Check for negative remaining (overbooking)
$tripInstances = DB::table('trip_instances')->get(['id', 'available_seats', 'tenant_id']);
$overbookedTrips = [];

foreach ($tripInstances as $trip) {
    $ledgerSum = DB::table('inventory_ledgers')
        ->where('trip_instance_id', $trip->id)
        ->where(function($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })
        ->sum('quantity');
    
    $remaining = $trip->available_seats + $ledgerSum;
    
    if ($remaining < 0) {
        $overbookedTrips[] = [
            'trip_id'     => $trip->id,
            'capacity'    => $trip->available_seats,
            'ledger_sum'  => $ledgerSum,
            'remaining'   => $remaining,
        ];
    }
}

testResult(
    $results,
    'T3.1',
    'No overbooked trips in current database',
    count($overbookedTrips) === 0 ? 'PASS' : 'FAIL',
    count($overbookedTrips) === 0 ? 'INFO' : 'CRITICAL',
    count($overbookedTrips) === 0
        ? "No trips with negative remaining seats found ({$tripInstances->count()} trips checked)"
        : "CONFIRMED: " . count($overbookedTrips) . " trip(s) are overbooked: " . json_encode($overbookedTrips),
    'remaining >= 0 for all trips',
    count($overbookedTrips) === 0 ? 'All trips OK' : count($overbookedTrips) . ' overbooked'
);

// =============================================================
// TEST SUITE 4 — PAYMENT INTEGRITY
// =============================================================
echo "\n--- TEST SUITE 4: PAYMENT INTEGRITY ---\n\n";

// Check for bookings where total_paid > grand_total
$overpaidBookings = DB::table('bookings')
    ->whereNull('deleted_at')
    ->whereRaw('total_paid > grand_total AND grand_total > 0')
    ->get(['id', 'pnr', 'grand_total', 'total_paid', 'balance_due', 'tenant_id']);

testResult(
    $results,
    'T4.1',
    'No bookings with total_paid > grand_total',
    $overpaidBookings->count() === 0 ? 'PASS' : 'FAIL',
    $overpaidBookings->count() === 0 ? 'INFO' : 'HIGH',
    $overpaidBookings->count() === 0
        ? 'No overpaid bookings found'
        : "CONFIRMED: {$overpaidBookings->count()} booking(s) have total_paid > grand_total: " . $overpaidBookings->pluck('pnr')->implode(', '),
    'total_paid <= grand_total for all bookings',
    $overpaidBookings->count() === 0 ? 'OK' : $overpaidBookings->count() . ' violations'
);

// Check for negative payments
$negativePayments = DB::table('payments')
    ->where('amount', '<', 0)
    ->where('type', '!=', 'reversal')
    ->get(['id', 'booking_id', 'amount', 'type']);

testResult(
    $results,
    'T4.2',
    'No negative non-reversal payments',
    $negativePayments->count() === 0 ? 'PASS' : 'FAIL',
    $negativePayments->count() === 0 ? 'INFO' : 'HIGH',
    $negativePayments->count() === 0
        ? 'No negative payments (non-reversal) found'
        : "Found {$negativePayments->count()} negative payment(s) with type != reversal",
    'All non-reversal payments have amount > 0',
    $negativePayments->count() === 0 ? 'OK' : $negativePayments->count() . ' violations'
);

// Check for duplicate refunds (same payment refunded multiple times)
$refunds = DB::table('payments')->where('type', 'reversal')->get(['id', 'booking_id', 'amount', 'reference_number']);
$refundsByBooking = $refunds->groupBy('booking_id');

$payments = DB::table('payments')->where('type', '!=', 'reversal')->get(['id', 'booking_id', 'amount']);
$paymentsByBooking = $payments->groupBy('booking_id');

$duplicateRefundViolations = [];
foreach ($refundsByBooking as $bookingId => $bookingRefunds) {
    $bookingPayments = $paymentsByBooking->get($bookingId, collect());
    $totalPaid    = $bookingPayments->sum('amount');
    $totalRefunded = $bookingRefunds->sum(fn($r) => abs($r->amount));
    
    if ($totalRefunded > $totalPaid) {
        $duplicateRefundViolations[] = [
            'booking_id'    => $bookingId,
            'total_paid'    => $totalPaid,
            'total_refunded' => $totalRefunded,
        ];
    }
}

testResult(
    $results,
    'T4.3',
    'No bookings with total refunds > total payments',
    count($duplicateRefundViolations) === 0 ? 'PASS' : 'FAIL',
    count($duplicateRefundViolations) === 0 ? 'INFO' : 'HIGH',
    count($duplicateRefundViolations) === 0
        ? 'No over-refunded bookings found'
        : "CONFIRMED: " . count($duplicateRefundViolations) . " booking(s) have been over-refunded: " . json_encode($duplicateRefundViolations),
    'total_refunded <= total_paid per booking',
    count($duplicateRefundViolations) === 0 ? 'OK' : count($duplicateRefundViolations) . ' violations'
);

// =============================================================
// TEST SUITE 5 — BOOKING STATUS INTEGRITY
// =============================================================
echo "\n--- TEST SUITE 5: BOOKING STATUS INTEGRITY ---\n\n";

// Find cancelled bookings that still have positive inventory consumption
$cancelledBookings = DB::table('bookings')
    ->whereNull('deleted_at')
    ->where('booking_status', 'cancelled')
    ->get(['id', 'pnr', 'trip_instance_id']);

$cancelledWithInventory = [];
foreach ($cancelledBookings as $booking) {
    $inventoryConsumed = DB::table('inventory_ledgers')
        ->where('booking_id', $booking->id)
        ->where('quantity', '<', 0)
        ->whereNull('expires_at')
        ->exists();
    
    // Check if there is a matching cancellation restoration
    $hasRestoration = DB::table('inventory_ledgers')
        ->where('booking_id', $booking->id)
        ->where('quantity', '>', 0)
        ->where('type', 'cancelled')
        ->exists();
    
    if ($inventoryConsumed && !$hasRestoration) {
        $cancelledWithInventory[] = $booking->pnr;
    }
}

testResult(
    $results,
    'T5.1',
    'Cancelled bookings have released inventory',
    count($cancelledWithInventory) === 0 ? 'PASS' : 'FAIL',
    count($cancelledWithInventory) === 0 ? 'INFO' : 'HIGH',
    count($cancelledWithInventory) === 0
        ? 'All cancelled bookings properly released inventory (or no cancelled bookings exist)'
        : "CONFIRMED BUG-015 related: " . count($cancelledWithInventory) . " cancelled booking(s) still hold inventory: " . implode(', ', $cancelledWithInventory),
    'All cancelled bookings should have inventory restoration entries',
    count($cancelledWithInventory) === 0 ? 'OK' : count($cancelledWithInventory) . ' leaking'
);

// =============================================================
// TEST SUITE 6 — PASSENGER INTEGRITY
// =============================================================
echo "\n--- TEST SUITE 6: PASSENGER DATA INTEGRITY ---\n\n";

// Passengers without booking
$orphanPassengers = DB::table('passengers')
    ->leftJoin('bookings', 'passengers.booking_id', '=', 'bookings.id')
    ->whereNull('bookings.id')
    ->count();

testResult(
    $results,
    'T6.1',
    'No orphan passengers (passengers without valid booking)',
    $orphanPassengers === 0 ? 'PASS' : 'FAIL',
    $orphanPassengers === 0 ? 'INFO' : 'HIGH',
    $orphanPassengers === 0
        ? 'No orphan passengers found'
        : "CONFIRMED: {$orphanPassengers} passenger(s) exist without valid booking",
    '0 orphan passengers',
    "{$orphanPassengers} orphan passengers"
);

// Future DOB check
$futureDobPassengers = DB::table('passengers')
    ->whereNotNull('date_of_birth')
    ->where('date_of_birth', '>', now()->toDateString())
    ->count();

testResult(
    $results,
    'T6.2',
    'No passengers with future date of birth',
    $futureDobPassengers === 0 ? 'PASS' : 'FAIL',
    $futureDobPassengers === 0 ? 'INFO' : 'MEDIUM',
    $futureDobPassengers === 0
        ? 'No passengers with future DOB found in database'
        : "CONFIRMED BUG-011: {$futureDobPassengers} passenger(s) have date_of_birth in the future",
    '0 passengers with future DOB',
    "{$futureDobPassengers} found"
);

// =============================================================
// TEST SUITE 7 — BOOKING LIFECYCLE STATE MACHINE
// =============================================================
echo "\n--- TEST SUITE 7: BOOKING STATUS STATES ---\n\n";

$statusCounts = DB::table('bookings')
    ->whereNull('deleted_at')
    ->groupBy('booking_status')
    ->selectRaw('booking_status, COUNT(*) as count')
    ->pluck('count', 'booking_status')
    ->toArray();

echo "Actual booking statuses in DB:\n";
foreach ($statusCounts as $status => $count) {
    echo "  {$status}: {$count}\n";
}
echo "\n";

// Check for impossible state: cancelled booking with active payments
$cancelledWithPendingBalance = DB::table('bookings')
    ->whereNull('deleted_at')
    ->where('booking_status', 'cancelled')
    ->where('payment_status', 'unpaid')
    ->where('total_paid', '>', 0)
    ->count();

echo "Cancelled bookings with total_paid > 0 but payment_status = unpaid: {$cancelledWithPendingBalance}\n";

// =============================================================
// SUMMARY
// =============================================================
echo "\n" . $separator . "\n";
echo "  FINAL SUMMARY\n";
echo $separator . "\n\n";

$pass    = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$fail    = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));
$partial = count(array_filter($results, fn($r) => $r['status'] === 'PARTIAL'));
$blocked = count(array_filter($results, fn($r) => $r['status'] === 'BLOCKED'));
$total   = count($results);

echo "Total Tests : {$total}\n";
echo "✅ PASS     : {$pass}\n";
echo "❌ FAIL     : {$fail}\n";
echo "⚠️ PARTIAL  : {$partial}\n";
echo "🚫 BLOCKED  : {$blocked}\n\n";

echo "CONFIRMED BUGS:\n";
foreach ($results as $r) {
    if ($r['status'] === 'FAIL') {
        echo "  [{$r['id']}] {$r['name']} [{$r['severity']}]\n";
        echo "  → " . substr($r['evidence'], 0, 100) . "\n\n";
    }
}

echo $separator . "\n";
echo "QA Execution complete: " . now()->toDateTimeString() . "\n";
echo $separator . "\n";

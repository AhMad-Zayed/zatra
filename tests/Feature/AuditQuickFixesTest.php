<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientSeatsException;
use App\Filament\Widgets\RevenueChart;
use App\Livewire\CustomerBookingPortal;
use App\Models\Customer;
use App\Models\InventoryLedger;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CreateBookingService;
use App\Services\Payments\ProcessGatewayPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the 5 audit quick fixes (UX_ARCHITECTURE_AUDIT.md executive summary):
 *  1. CustomerBookingPortal's seat map reading the real `available_seats` column instead of the
 *     nonexistent `seats_count` (which always silently fell back to a hardcoded 50-seat grid).
 *  2. RevenueChart grouping revenue by currency into separate, clearly-labeled series instead of
 *     summing all currencies into one number.
 *  3. BookingService::reopenBooking() restoring passengers + re-consuming inventory through the
 *     same locked, capacity-checked path as CreateBookingService, failing loudly with zero state
 *     change when capacity is no longer available.
 *  4. Dead code removal (BookingService::createBooking()/ensureCapacity()/generateReference(),
 *     Livewire\Storefront\Checkout) — covered by the rewritten tests in BookingAndFinancialEngineTest,
 *     DashboardAnalyticsTest, NotificationSystemTest, and StorefrontAndPortalTest.
 *  5. ProcessGatewayPaymentService delegating to BookingService::recordPayment() for the same
 *     currency-check + recalculateTotals()-driven totals every other payment path uses.
 */
class AuditQuickFixesTest extends TestCase
{
    use RefreshDatabase;

    private CreateBookingService $createBookingService;
    private BookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingService = new CreateBookingService();
        $this->bookingService = new BookingService();
    }

    /**
     * @return array{tenant: Tenant, customer: Customer, instance: TripInstance, cat: TripPassengerCategory}
     */
    private function makeFixture(string $suffix, int $availableSeats = 10, float $price = 100.00, string $currency = 'USD'): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-aqf-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0593{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => $price, 'currency' => $currency]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => $availableSeats,
            'status' => 'active',
            'currency' => $currency,
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => $price, 'requires_seat' => true,
        ]);

        return compact('tenant', 'customer', 'instance', 'cat');
    }

    private function makeAgencyAdmin(Tenant $tenant, string $phone): User
    {
        Role::firstOrCreate(['name' => 'agency_admin']);
        Permission::firstOrCreate(['name' => 'panel_access_placeholder']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create(['name' => 'Admin', 'phone' => $phone]);
        $user->tenants()->attach($tenant);
        setPermissionsTeamId($tenant->id);
        $user->assignRole('agency_admin');

        return $user;
    }

    // ------------------------------------------------------------------
    // 1. CustomerBookingPortal seat map
    // ------------------------------------------------------------------

    public function test_customer_portal_seat_map_uses_real_trip_capacity_not_hardcoded_fifty(): void
    {
        $f = $this->makeFixture('001', availableSeats: 3);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $component = Livewire::test(CustomerBookingPortal::class, ['uuid' => $booking->uuid]);

        $component->assertSet('totalSeats', 3);
        $this->assertCount(3, $component->get('availableSeats'), 'Seat grid must match the trip\'s real available_seats (3), not a hardcoded 50.');
        $this->assertSame([1 => true, 2 => true, 3 => true], $component->get('availableSeats'));
    }

    public function test_customer_portal_seat_map_marks_seats_taken_by_other_bookings(): void
    {
        $f = $this->makeFixture('002', availableSeats: 3);

        $bookingA = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => 'A'],
            ],
        ]);
        // Seat 2 is already assigned on a different booking for the same trip.
        Passenger::where('booking_id', $bookingA->id)->first()->update(['seat_number' => '2']);

        $customerB = Customer::create(['name' => 'Bob', 'phone' => '0593002b', 'tenant_id' => $f['tenant']->id]);
        $bookingB = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $customerB->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => 'B'],
            ],
        ]);

        $component = Livewire::test(CustomerBookingPortal::class, ['uuid' => $bookingB->uuid]);

        $this->assertSame([1 => true, 2 => false, 3 => true], $component->get('availableSeats'));
    }

    // ------------------------------------------------------------------
    // 2. RevenueChart currency grouping
    // ------------------------------------------------------------------

    public function test_revenue_chart_separates_series_by_currency(): void
    {
        $f = $this->makeFixture('003');
        $admin = $this->makeAgencyAdmin($f['tenant'], '0791100003');

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        Payment::create([
            'tenant_id' => $f['tenant']->id, 'booking_id' => $booking->id,
            'amount' => 100.00, 'currency' => 'USD', 'payment_method' => 'cash',
        ]);
        Payment::create([
            'tenant_id' => $f['tenant']->id, 'booking_id' => $booking->id,
            'amount' => 200.00, 'currency' => 'ILS', 'payment_method' => 'cash',
        ]);

        Filament::setTenant($f['tenant'], true);
        $this->actingAs($admin);

        $widget = new RevenueChart();
        $method = new \ReflectionMethod(RevenueChart::class, 'getData');
        $method->setAccessible(true);
        $data = $method->invoke($widget);

        $this->assertCount(2, $data['datasets'], 'Must produce one series per currency actually present, not one mixed sum.');

        $labels = array_column($data['datasets'], 'label');
        $this->assertContains('الإيرادات المحصلة (USD)', $labels);
        $this->assertContains('الإيرادات المحصلة (ILS)', $labels);

        $currentMonthIndex = now()->month - 1;
        foreach ($data['datasets'] as $dataset) {
            if ($dataset['label'] === 'الإيرادات المحصلة (USD)') {
                $this->assertEquals(100.00, $dataset['data'][$currentMonthIndex]);
            } else {
                $this->assertEquals(200.00, $dataset['data'][$currentMonthIndex]);
            }
        }
    }

    // ------------------------------------------------------------------
    // 3. reopen_cancelled -> BookingService::reopenBooking()
    // ------------------------------------------------------------------

    public function test_reopen_booking_restores_passengers_reconsumes_inventory_and_clears_refund_pending(): void
    {
        $f = $this->makeFixture('004', availableSeats: 5, price: 100.00);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $this->bookingService->recordPayment($booking, 100.00, 'cash', null, \App\Enums\PaymentType::FULL);
        $this->assertEquals(PaymentStatus::Paid, $booking->fresh()->payment_status);

        $this->bookingService->cancelBooking($booking, 'customer_request');
        $cancelled = $booking->fresh();
        $this->assertEquals(BookingStatus::Cancelled, $cancelled->booking_status);
        $this->assertEquals(PaymentStatus::RefundPending, $cancelled->payment_status);
        $this->assertSame(0, Passenger::where('booking_id', $booking->id)->count(), 'cancelBooking() soft-deletes passengers.');
        $this->assertEquals(5, $f['instance']->fresh()->getRemainingSeatsAttribute(), 'Cancellation must release the seat.');

        $this->bookingService->reopenBooking($booking);

        $reopened = $booking->fresh();
        $this->assertEquals(BookingStatus::Confirmed, $reopened->booking_status, 'A fully-paid booking must land back on Confirmed via the normal recalculateTotals() transition, not stay Pending.');
        $this->assertEquals(PaymentStatus::Paid, $reopened->payment_status, 'RefundPending must be superseded once the booking is active again with its original payment intact.');
        $this->assertSame(1, Passenger::where('booking_id', $booking->id)->count(), 'Passengers must be restored.');
        $this->assertEquals(4, $f['instance']->fresh()->getRemainingSeatsAttribute(), 'The seat must be re-consumed on reopen.');

        $passenger = Passenger::where('booking_id', $booking->id)->first();
        $this->assertArrayNotHasKey('cancelled_at', $passenger->extra_preferences ?? []);
    }

    public function test_reopen_booking_fails_cleanly_with_zero_state_change_when_seats_no_longer_available(): void
    {
        $f = $this->makeFixture('005', availableSeats: 2, price: 100.00);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '2'],
            ],
        ]);

        $this->bookingService->cancelBooking($booking, 'customer_request');
        $this->assertEquals(2, $f['instance']->fresh()->getRemainingSeatsAttribute());

        // The 2 released seats get resold to a different customer in the meantime.
        $otherCustomer = Customer::create(['name' => 'Other', 'phone' => '0593005o', 'tenant_id' => $f['tenant']->id]);
        $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $otherCustomer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'Other', 'last_name' => '1'],
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'Other', 'last_name' => '2'],
            ],
        ]);
        $this->assertEquals(0, $f['instance']->fresh()->getRemainingSeatsAttribute());

        $ledgerCountBefore = InventoryLedger::count();

        $this->expectException(InsufficientSeatsException::class);
        try {
            $this->bookingService->reopenBooking($booking);
        } finally {
            $fresh = $booking->fresh();
            $this->assertEquals(BookingStatus::Cancelled, $fresh->booking_status, 'A failed reopen must leave booking_status untouched.');
            $this->assertSame(0, Passenger::where('booking_id', $booking->id)->count(), 'A failed reopen must not restore passengers.');
            $this->assertSame($ledgerCountBefore, InventoryLedger::count(), 'A failed reopen must write no ledger rows.');
            $this->assertEquals(0, $f['instance']->fresh()->getRemainingSeatsAttribute(), 'A failed reopen must not change trip availability.');
        }
    }

    // ------------------------------------------------------------------
    // 5. ProcessGatewayPaymentService
    // ------------------------------------------------------------------

    public function test_gateway_payment_stamps_booking_currency_and_recalculates_via_canonical_path(): void
    {
        $f = $this->makeFixture('006', price: 100.00, currency: 'ILS');

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);
        $this->assertEquals('ILS', $booking->currency);

        $payment = app(ProcessGatewayPaymentService::class)->execute([
            'transaction_id' => 'tx_gw_006',
            'amount' => 100.00,
            'method' => 'Stripe',
            'booking_id' => $booking->id,
            'tenant_id' => $f['tenant']->id,
        ]);

        $this->assertNotNull($payment);
        $this->assertEquals('ILS', $payment->currency, 'Gateway payment must be stamped with the booking\'s real currency, not left null.');

        $fresh = $booking->fresh();
        $this->assertEquals(100.00, $fresh->total_paid);
        $this->assertEquals(0.00, $fresh->balance_due);
        $this->assertEquals(PaymentStatus::Paid, $fresh->payment_status);
        $this->assertEquals(BookingStatus::Confirmed, $fresh->booking_status);
    }

    public function test_gateway_payment_idempotency_skips_duplicate_transaction_id(): void
    {
        $f = $this->makeFixture('007', price: 100.00);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $service = app(ProcessGatewayPaymentService::class);

        $first = $service->execute([
            'transaction_id' => 'tx_gw_007',
            'amount' => 100.00,
            'method' => 'Stripe',
            'booking_id' => $booking->id,
            'tenant_id' => $f['tenant']->id,
        ]);
        $this->assertNotNull($first);

        $second = $service->execute([
            'transaction_id' => 'tx_gw_007',
            'amount' => 100.00,
            'method' => 'Stripe',
            'booking_id' => $booking->id,
            'tenant_id' => $f['tenant']->id,
        ]);
        $this->assertNull($second, 'A repeated webhook delivery for the same transaction_id must be an idempotent no-op.');

        $this->assertEquals(1, Payment::where('booking_id', $booking->id)->count());
        $this->assertEquals(100.00, $booking->fresh()->total_paid);
    }
}

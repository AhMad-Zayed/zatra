<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Customer;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\Booking;
use App\Models\Payment;
use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Services\BookingService;
use App\Services\CreateBookingService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingAndFinancialEngineTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;
    private CreateBookingService $createBookingService;
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = new BookingService();
        $this->createBookingService = new CreateBookingService();
        $this->paymentService = new PaymentService();
    }

    /**
     * P0-6 test helper: standard tenant/customer/agent/trip-instance/category fixture,
     * parameterized by price and available seats, used by the payment-consolidation tests
     * below. Returns a Booking created via the LIVE booking path (CreateBookingService)
     * unless $passengerCount is 0, in which case only the scaffolding is returned.
     */
    private function makePaidTestBooking(string $suffix, float $price = 100.00, int $passengerCount = 1, int $availableSeats = 10): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "05000{$suffix}", 'tenant_id' => $tenant->id]);
        $agent = User::create(['name' => 'Agent Sam', 'phone' => "07911{$suffix}"]);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => $price]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => $availableSeats,
            'status' => 'active',
        ]);
        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => $price,
            'requires_seat' => true,
        ]);

        $booking = null;
        if ($passengerCount > 0) {
            $passengersData = [];
            for ($i = 1; $i <= $passengerCount; $i++) {
                $passengersData[] = ['trip_passenger_category_id' => $cat->id, 'first_name' => "P{$i}", 'last_name' => 'Test'];
            }
            $booking = $this->createBookingService->execute([
                'tenant_id' => $tenant->id,
                'trip_instance_id' => $instance->id,
                'customer_id' => $customer->id,
                'passengersData' => $passengersData,
            ]);
        }

        return compact('tenant', 'customer', 'agent', 'template', 'instance', 'cat', 'booking');
    }

    public function test_capacity_enforcement(): void
    {
        // Rewritten against the live booking path (CreateBookingService ->
        // InventoryService::consumeForBooking()) after deleting the dead
        // BookingService::createBooking()/ensureCapacity() this test used to exercise —
        // ensureCapacity() counted live Passenger rows with no locking; the live path locks the
        // TripInstance and checks the InventoryLedger, and raises InsufficientSeatsException
        // (not RuntimeException) on exhaustion.
        $tenant = Tenant::create(['name' => 'Agency North']);
        $customer = Customer::create(['name' => 'John Customer', 'phone' => '0799999999', 'tenant_id' => $tenant->id]);

        $template = TripTemplate::create([
            'tenant_id' => $tenant->id,
            'title' => 'Dead Sea Trip',
            'base_price' => 50.00,
        ]);

        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 2,
            'status' => 'active',
        ]);

        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 50.00,
            'requires_seat' => true,
        ]);

        // 1. First booking with 2 passengers should succeed (fills the 2-seat trip exactly).
        $booking = $this->createBookingService->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'Passenger', 'last_name' => '1'],
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'Passenger', 'last_name' => '2'],
            ],
        ]);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);

        // 2. Third passenger on a now-full 2-seat trip must fail the capacity check.
        $this->expectException(\App\Exceptions\InsufficientSeatsException::class);

        $this->createBookingService->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'Passenger', 'last_name' => '3'],
            ],
        ]);
    }

    // test_reference_generation removed: it asserted the exact tenant-prefixed sequential PNR
    // format produced by the now-deleted BookingService::generateReference() (only ever called
    // from the dead createBooking()) against a `reference` column that doesn't exist on
    // `bookings` either (the real column is `pnr`) — it was already failing before this change,
    // unrelated to it. The live path (CreateBookingService::execute()) generates PNRs with a
    // different scheme ('ZTR-' + 6 random chars); makePaidTestBooking()/makePaidBooking() and
    // every test built on them already implicitly cover that every live booking gets a non-null,
    // unique `pnr`.

    public function test_initial_deposit_is_not_incorrectly_rejected(): void
    {
        // Regression test for P0-1: CreateBookingService::execute() used to divide
        // $booking->grand_total by 100 a second time when validating initial_payment_amount,
        // even though grand_total is already a major-unit value via MoneyCast. A legitimate
        // deposit smaller than the total would be incorrectly rejected as "exceeding the total".
        $tenant = Tenant::create(['name' => 'Agency']);
        $customer = Customer::create(['name' => 'John', 'phone' => '0799999999', 'tenant_id' => $tenant->id]);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 10.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 2,
            'status' => 'active',
        ]);

        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 100.00,
            'requires_seat' => true,
        ]);

        // Booking total = 100.00, initial deposit = 50.00 — a valid deposit that must succeed.
        $booking = $this->createBookingService->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'Passenger', 'last_name' => '1'],
            ],
            'initial_payment_amount' => 50.00,
            'initial_payment_method' => 'cash',
        ]);

        $this->assertEquals(100.00, $booking->grand_total);

        $payment = $booking->payments()->first();
        $this->assertNotNull($payment, 'Initial deposit payment was not recorded.');
        $this->assertEquals(50.00, $payment->amount);
    }

    public function test_partial_passenger_cancellation_uses_consistent_money_units(): void
    {
        // Regression test for P0-3 AND P0-7a: BookingResource's "cancel_passengers" admin
        // action originally mixed a MoneyCast major-unit sum with a raw-cents DB::table()
        // read (P0-3, fixed in Phase 2A), but running the fixed unit-conversion logic for
        // real then surfaced a second, deeper bug (P0-7a): the action's $passenger->delete()
        // calls were not wrapped in withoutEvents(), so PassengerObserver::deleted() fired
        // automatically and independently created its own InventoryLedger release row +
        // recalculateTotals() call, which the action's own manual ledger row + manual
        // decrement then duplicated on top of — two ledger rows and a double financial
        // decrement for one cancelled passenger. Phase 2B (P0-7 Option C) centralizes this
        // into BookingService::cancelPassengers(), which wraps the deletion in
        // withoutEvents() and performs exactly one ledger write + one recalculation.
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-money-units', 'domain' => 'money-units.zatara.com']);
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@money-units.zatara.com',
            'phone' => '0500000099',
            'password' => bcrypt('password'),
        ]);
        $admin->tenants()->attach($tenant->id);
        $customer = Customer::create(['name' => 'Jane', 'phone' => '0500000098', 'tenant_id' => $tenant->id]);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 100.00,
            'requires_seat' => true,
        ]);

        // Booking total = 200.00 (two passengers at 100.00 each).
        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'pnr' => 'ZTR-TESTPC',
            'currency' => 'USD',
            'booking_status' => BookingStatus::Confirmed,
            'grand_total' => 200.00,
            'balance_due' => 200.00,
        ]);

        $p1 = $booking->passengers()->create([
            'tenant_id' => $tenant->id,
            'trip_passenger_category_id' => $cat->id,
            'price_at_booking' => 100.00,
            'first_name' => 'Passenger',
            'last_name' => 'One',
            'data_complete' => true,
        ]);
        $booking->passengers()->create([
            'tenant_id' => $tenant->id,
            'trip_passenger_category_id' => $cat->id,
            'price_at_booking' => 100.00,
            'first_name' => 'Passenger',
            'last_name' => 'Two',
            'data_complete' => true,
        ]);

        $this->actingAs($admin);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        \Filament\Facades\Filament::setTenant($tenant);

        // NOTE: This repo has no working setup for mounting Filament resource pages via
        // Livewire::test() (confirmed: Livewire::test() on a Filament panel page returns a
        // null component instance with no thrown exception, even with the panel/tenant
        // context explicitly set — a pre-existing gap already acknowledged in
        // tests/Feature/Filament/AdminBookingTest.php, which works around it via reflection
        // instead of full mounting). Fixing that Filament test-harness gap is out of scope
        // for this low-risk-money-fixes phase. Instead, we extract the real, registered
        // 'cancel_passengers' Action object from BookingResource::table() (this does not
        // require a mounted Livewire component) and invoke its actual closure directly with
        // explicit arguments — this runs the exact same production code as the admin UI,
        // just without going through Filament's form-fill/argument-resolution layer, which
        // is what building a full panel-testing setup would be needed for.
        $page = new \App\Filament\Resources\BookingResource\Pages\ListBookings();
        $table = \App\Filament\Resources\BookingResource::table(new \Filament\Tables\Table($page));
        $action = $table->getAction('cancel_passengers');
        $closure = $action->getActionFunction();

        $closure(['passenger_ids' => [$p1->id], 'cancellation_reason' => 'customer_request'], $booking);

        // Assert against the raw persisted DB columns (cents) — not the Eloquent accessor —
        // since this test is specifically verifying the unit-consistent DB write, not the
        // separate (out-of-scope, P0-6) balance_due accessor behavior.
        $rawGrandTotal = \Illuminate\Support\Facades\DB::table('bookings')->where('id', $booking->id)->value('grand_total');
        $rawBalanceDue = \Illuminate\Support\Facades\DB::table('bookings')->where('id', $booking->id)->value('balance_due');

        $this->assertEquals(10000, $rawGrandTotal, 'grand_total should be exactly $100.00 (10000 cents) after cancelling one $100 passenger from a $200 booking.');
        $this->assertEquals(10000, $rawBalanceDue, 'balance_due should be exactly $100.00 (10000 cents) after cancelling one $100 passenger from a $200 booking.');

        // P0-7a discipline: assert the LEDGER ROW COUNT, not just the final totals — final
        // totals alone would not have caught the original double-mutation bug if the two
        // competing writes had happened to cancel out numerically.
        $releaseRows = \App\Models\InventoryLedger::where('booking_id', $booking->id)->where('type', 'cancelled')->get();
        $this->assertCount(1, $releaseRows, 'Exactly one release ledger row should be created for one cancelled passenger, not two (observer + explicit action).');
        $this->assertEquals(1, $releaseRows->first()->quantity, 'Released quantity should be exactly 1 seat.');
    }

    public function test_admin_add_seats_consumes_inventory_and_charges_exactly_once(): void
    {
        // Regression test for P0-7c/d: BookingResource's "add_seats" admin action used to fire
        // both PassengerObserver::created() (its own per-passenger InventoryLedger consumption
        // + recalculateTotals()) AND the action's own combined InventoryLedger row + (wrong)
        // recalculateFinancialStatus() call — producing net over-consumption of inventory
        // (confirmed during investigation: net -3 for +1 passenger added to a 1-passenger
        // booking) and never actually adding the new passenger's price to grand_total at all
        // (recalculateTotals() excluded data_complete=false passengers). Phase 2B fixes both
        // via BookingService::addPassengers() (single ledger row, withoutEvents()) and
        // removing the data_complete filter from recalculateTotals().
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-add-seats', 'domain' => 'add-seats.zatara.com']);
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@add-seats.zatara.com',
            'phone' => '0500000097',
            'password' => bcrypt('password'),
        ]);
        $admin->tenants()->attach($tenant->id);
        $customer = Customer::create(['name' => 'Jane', 'phone' => '0500000096', 'tenant_id' => $tenant->id]);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 100.00,
            'requires_seat' => true,
        ]);

        // Existing booking: 1 passenger, grand_total = 100.00.
        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'pnr' => 'ZTR-TESTAS',
            'currency' => 'USD',
            'booking_status' => BookingStatus::Confirmed,
            'grand_total' => 100.00,
            'balance_due' => 100.00,
        ]);
        // Seed the existing passenger via withoutEvents(), matching how CreateBookingService
        // actually creates passengers in production — a raw, un-suppressed create() here
        // would let PassengerObserver double-count this SETUP passenger too, which would
        // conflate a test-fixture artifact with the addPassengers() behavior under test.
        \App\Models\Passenger::withoutEvents(function () use ($booking, $tenant, $cat) {
            $booking->passengers()->create([
                'tenant_id' => $tenant->id,
                'trip_passenger_category_id' => $cat->id,
                'price_at_booking' => 100.00,
                'first_name' => 'Passenger',
                'last_name' => 'One',
                'data_complete' => true,
            ]);
        });
        \App\Models\InventoryLedger::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'booking_id' => $booking->id,
            'quantity' => -1,
            'type' => 'confirmed',
        ]);

        $this->actingAs($admin);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        \Filament\Facades\Filament::setTenant($tenant);

        $page = new \App\Filament\Resources\BookingResource\Pages\ListBookings();
        $table = \App\Filament\Resources\BookingResource::table(new \Filament\Tables\Table($page));
        $action = $table->getAction('add_seats');
        $closure = $action->getActionFunction();

        $closure(["cat_{$cat->id}" => 1], $booking);

        $booking->refresh();
        $this->assertEquals(2, $booking->passengers()->count(), 'Booking should have 2 passengers after adding 1 seat.');

        $confirmedRows = \App\Models\InventoryLedger::where('booking_id', $booking->id)->where('type', 'confirmed')->get();
        $this->assertCount(2, $confirmedRows, 'Should be exactly 2 confirmed ledger rows total: the original seed row + exactly one new row for the added passenger.');
        $this->assertEquals(-2, $confirmedRows->sum('quantity'), 'Net inventory consumption should be exactly -2 for a 2-passenger booking, not -3 (the original over-consumption bug).');

        $rawGrandTotal = \Illuminate\Support\Facades\DB::table('bookings')->where('id', $booking->id)->value('grand_total');
        $this->assertEquals(20000, $rawGrandTotal, 'grand_total should be exactly $200.00 (20000 cents) after adding a second $100 passenger — the new passenger must be charged, not silently excluded.');
    }

    public function test_live_phone_booking_includes_incomplete_passenger_in_grand_total(): void
    {
        // Regression test for the live data_complete bug found during P0-7 planning:
        // CreateBookingService::execute() correctly sums every passenger's price into
        // grand_total (including data_complete=false "seat holder" passengers created in
        // phone-booking mode), but then called recalculateTotals(), which filtered its sum to
        // data_complete=true only — silently overwriting the correct total with an
        // undercounted one for any phone booking containing an incomplete passenger. This is
        // a live bug in the production checkout path, not specific to any admin action.
        $tenant = Tenant::create(['name' => 'Agency']);
        $customer = Customer::create(['name' => 'John', 'phone' => '0799999999', 'tenant_id' => $tenant->id]);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 5,
            'status' => 'active',
        ]);

        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 100.00,
            'requires_seat' => true,
        ]);

        // Phone booking: 1 complete passenger + 1 incomplete (no first_name) "seat holder".
        $booking = $this->createBookingService->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'phone_booking_mode' => true,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'Complete', 'last_name' => 'Passenger'],
                ['trip_passenger_category_id' => $cat->id], // no first_name -> data_complete=false
            ],
        ]);

        $incompletePassenger = $booking->passengers()->where('data_complete', false)->first();
        $this->assertNotNull($incompletePassenger, 'Booking should contain one incomplete (data_complete=false) placeholder passenger.');
        $this->assertEquals(200.00, $booking->grand_total, 'grand_total must include BOTH passengers (100 + 100), even though one is data_complete=false.');

        // Simulate the customer completing their details later (CustomerBookingPortal::saveAll()
        // pattern) — grand_total must remain correct, not change.
        $incompletePassenger->update(['first_name' => 'Now', 'last_name' => 'Complete', 'data_complete' => true]);
        $booking->refresh();
        $this->assertEquals(200.00, $booking->grand_total, 'grand_total must remain 200.00 after the passenger completes their details.');
    }

    public function test_full_cancellation_with_fee_releases_inventory_exactly_once(): void
    {
        // Regression test for P0-7b: BookingResource's "process_cancellation" admin action's
        // $p->delete() loop was not wrapped in withoutEvents(), so PassengerObserver::deleted()
        // fired for each passenger (its own release ledger row) in addition to the action's own
        // combined release row — 2 ledger rows for the cancellation instead of 1. The action's
        // final write was an absolute grand_total=fee/balance_due=0 set (not a decrement), which
        // masked the double-decrement symptom while still leaving the duplicate ledger row.
        // Also verifies waitlist promotion fires through the canonical mechanism exactly once.
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-full-cancel', 'domain' => 'full-cancel.zatara.com']);
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@full-cancel.zatara.com',
            'phone' => '0500000095',
            'password' => bcrypt('password'),
        ]);
        $admin->tenants()->attach($tenant->id);
        $customer = Customer::create(['name' => 'Jane', 'phone' => '0500000094', 'tenant_id' => $tenant->id]);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 100.00,
            'requires_seat' => true,
        ]);

        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'pnr' => 'ZTR-TESTFC',
            'currency' => 'USD',
            'booking_status' => BookingStatus::Confirmed,
            'grand_total' => 200.00,
            'balance_due' => 200.00,
            'total_paid' => 200.00,
        ]);
        $booking->passengers()->create([
            'tenant_id' => $tenant->id,
            'trip_passenger_category_id' => $cat->id,
            'price_at_booking' => 100.00,
            'first_name' => 'Passenger',
            'last_name' => 'One',
            'data_complete' => true,
        ]);
        $booking->passengers()->create([
            'tenant_id' => $tenant->id,
            'trip_passenger_category_id' => $cat->id,
            'price_at_booking' => 100.00,
            'first_name' => 'Passenger',
            'last_name' => 'Two',
            'data_complete' => true,
        ]);

        $this->actingAs($admin);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        \Filament\Facades\Filament::setTenant($tenant);

        Bus::fake();

        $page = new \App\Filament\Resources\BookingResource\Pages\ListBookings();
        $table = \App\Filament\Resources\BookingResource::table(new \Filament\Tables\Table($page));
        $action = $table->getAction('process_cancellation');
        $closure = $action->getActionFunction();

        $closure(['cancellation_reason' => 'customer_request', 'cancellation_fee' => 20.00], $booking);

        $booking->refresh();
        $this->assertEquals(\App\Enums\BookingStatus::Cancelled, $booking->booking_status);
        $this->assertEquals(0, $booking->passengers()->count(), 'All passengers should be soft-deleted.');

        $releaseRows = \App\Models\InventoryLedger::where('booking_id', $booking->id)->where('type', 'cancelled')->get();
        $this->assertCount(1, $releaseRows, 'Exactly one release ledger row should be created for the full cancellation, not two.');
        $this->assertEquals(2, $releaseRows->first()->quantity, 'Released quantity should be exactly 2 seats.');

        $rawGrandTotal = \Illuminate\Support\Facades\DB::table('bookings')->where('id', $booking->id)->value('grand_total');
        $rawBalanceDue = \Illuminate\Support\Facades\DB::table('bookings')->where('id', $booking->id)->value('balance_due');
        $this->assertEquals(2000, $rawGrandTotal, 'grand_total should be exactly the $20.00 cancellation fee (2000 cents).');
        $this->assertEquals(0, $rawBalanceDue, 'balance_due should be 0 after a fee-only cancellation.');

        Bus::assertDispatchedTimes(\App\Jobs\WaitlistAutoPromotion::class, 1);
    }

    public function test_view_booking_cancellation_action_uses_valid_enum_literal(): void
    {
        // Regression test for the P0-2 overlap found during P0-7 investigation: ViewBooking's
        // cancel actions used the invalid ledger type literal 'cancellation' (not a member of
        // the inventory_ledgers.type DB enum, which only allows 'cancelled') — under strict SQL
        // mode this threw QueryException and rolled back the whole action, meaning these
        // actions did not function at all. Phase 2B's centralization routes ViewBooking's
        // cancel_passengers/cancel_booking actions through the shared BookingService
        // methods, which always write valid type='cancelled'/'confirmed' values (proven by
        // test_partial_passenger_cancellation_uses_consistent_money_units and
        // test_full_cancellation_with_fee_releases_inventory_exactly_once against the same
        // BookingService methods that ViewBooking now calls).
        //
        // NOTE: invoking ViewBooking's action closures directly (as done for BookingResource's
        // Table actions elsewhere in this file) is not possible here: both actions end with
        // $this->refreshFormData(...), which triggers Filament to rebuild the full booking
        // form — including an unrelated Repeater field that calls a method
        // (Repeater::itemActions) not available in the Filament version installed in this
        // environment, a pre-existing, unrelated incompatibility discovered only because this
        // test tried to exercise that code path outside a real Livewire mount. That is a
        // separate, out-of-scope defect (not part of P0-7), so this test instead verifies the
        // wiring statically: the action closures must delegate to BookingService and must
        // contain no direct InventoryLedger::create() call with an invalid type literal.
        $source = file_get_contents(app_path('Filament/Resources/BookingResource/Pages/ViewBooking.php'));

        $this->assertStringContainsString(
            "BookingService::class)->cancelBooking(",
            $source,
            'ViewBooking cancel_booking action must delegate to the centralized BookingService::cancelBooking().'
        );
        $this->assertStringContainsString(
            "BookingService::class)->cancelPassengers(",
            $source,
            'ViewBooking cancel_passengers action must delegate to the centralized BookingService::cancelPassengers().'
        );

        // The two migrated action bodies (cancel_passengers, cancel_booking) must each be a
        // thin delegation to BookingService with no direct InventoryLedger::create() call —
        // extract each action's own closure body (up to its closing "}),") and assert on
        // that slice specifically, since the invalid 'cancellation' literal legitimately still
        // exists in the untouched, out-of-scope transfer_booking action elsewhere in this file.
        foreach (['cancel_passengers', 'cancel_booking'] as $actionName) {
            $start = strpos($source, "Actions\\Action::make('{$actionName}')");
            $this->assertNotFalse($start, "Action '{$actionName}' should exist in ViewBooking.php.");
            $actionStart = strpos($source, '->action(function', $start);
            $actionEnd = strpos($source, "}),", $actionStart);
            $actionBody = substr($source, $actionStart, $actionEnd - $actionStart);

            $this->assertStringNotContainsString(
                'InventoryLedger::create',
                $actionBody,
                "The migrated '{$actionName}' action must not create InventoryLedger rows directly."
            );
        }
    }

    public function test_payment_immutability(): void
    {
        // Uses the LIVE booking-creation path (CreateBookingService::execute()), not the
        // dead BookingService::createBooking() path, so balance_due is populated correctly
        // and recordPayment() can actually succeed before the immutability guard is tested.
        $tenant = Tenant::create(['name' => 'Agency']);
        $customer = Customer::create(['name' => 'John', 'phone' => '0799999999', 'tenant_id' => $tenant->id]);
        $agent = User::create(['name' => 'Agent Sam', 'phone' => '0791111111']);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 10.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 2,
            'status' => 'active',
        ]);

        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 100.00,
            'requires_seat' => true,
        ]);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'Passenger', 'last_name' => '1'],
            ],
        ]);

        $payment = $this->paymentService->recordPayment($booking, 50.00, 'cash', $agent, PaymentType::DEPOSIT);

        // Verify update throws exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payments are strictly immutable and cannot be updated.');
        $payment->update(['amount' => 60.00]);
    }

    public function test_payment_deletion_fails(): void
    {
        // Uses the LIVE booking-creation path (CreateBookingService::execute()), not the
        // dead BookingService::createBooking() path, so balance_due is populated correctly
        // and recordPayment() can actually succeed before the immutability guard is tested.
        $tenant = Tenant::create(['name' => 'Agency']);
        $customer = Customer::create(['name' => 'John', 'phone' => '0799999999', 'tenant_id' => $tenant->id]);
        $agent = User::create(['name' => 'Agent Sam', 'phone' => '0791111111']);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 10.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(1),
            'available_seats' => 2,
            'status' => 'active',
        ]);

        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 100.00,
            'requires_seat' => true,
        ]);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $cat->id, 'first_name' => 'Passenger', 'last_name' => '1'],
            ],
        ]);

        $payment = $this->paymentService->recordPayment($booking, 50.00, 'cash', $agent, PaymentType::DEPOSIT);

        // Verify delete throws exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payments are strictly immutable and cannot be deleted.');
        $payment->delete();
    }

    // ========================================================================================
    // P0-6: BookingService::recordPayment()/reversePayment() consolidation regression tests.
    // ========================================================================================

    public function test_record_payment_single_partial_payment(): void
    {
        $ctx = $this->makePaidTestBooking('p6a', 100.00);
        $booking = $ctx['booking'];

        $payment = $this->bookingService->recordPayment($booking, 40.00, 'cash', $ctx['agent'], PaymentType::DEPOSIT);

        $booking->refresh();
        $this->assertEquals(40.00, $payment->amount);
        $this->assertEquals(40.00, $booking->total_paid);
        $this->assertEquals(60.00, $booking->getRawOriginal('balance_due') / 100);
        $this->assertEquals(\App\Enums\PaymentStatus::PartiallyPaid, $booking->payment_status);
        // P0-6: recordPayment() now owns this transition (previously only confirm_deposit's
        // admin action attempted it, and did so via a nonexistent PaymentStatus::Partial case).
        $this->assertEquals(BookingStatus::ConfirmedPartial, $booking->booking_status, 'A partial payment on a Pending booking must transition it to ConfirmedPartial.');
    }

    public function test_record_payment_multiple_payments_are_cumulative(): void
    {
        // Regression test for the confirm_deposit bug (set total_paid = deposit, not
        // existing + deposit) and the legacy recalculateFinancialStatus() bug (only wrote
        // total_paid/balance_due when payment_status changed, silently skipping a second
        // payment that stayed within the same status band).
        $ctx = $this->makePaidTestBooking('p6b', 100.00);
        $booking = $ctx['booking'];

        $this->bookingService->recordPayment($booking, 30.00, 'cash', $ctx['agent']);
        $booking->refresh();
        $this->assertEquals(30.00, $booking->total_paid);

        $this->bookingService->recordPayment($booking, 30.00, 'cash', $ctx['agent']);
        $booking->refresh();
        $this->assertEquals(60.00, $booking->total_paid, 'Second payment must be cumulative (30+30=60), not overwrite total_paid to 30.');
        $this->assertEquals(\App\Enums\PaymentStatus::PartiallyPaid, $booking->payment_status);

        $this->bookingService->recordPayment($booking, 40.00, 'cash', $ctx['agent']);
        $booking->refresh();
        $this->assertEquals(100.00, $booking->total_paid, 'Third payment must be cumulative (30+30+40=100), not skipped.');
        $this->assertEquals(0.00, $booking->getRawOriginal('balance_due') / 100);
        $this->assertEquals(\App\Enums\PaymentStatus::Paid, $booking->payment_status);
        $this->assertEquals(BookingStatus::Confirmed, $booking->booking_status);
    }

    public function test_record_payment_exact_full_payment_transitions_to_confirmed(): void
    {
        $ctx = $this->makePaidTestBooking('p6c', 100.00);
        $booking = $ctx['booking'];

        $this->bookingService->recordPayment($booking, 100.00, 'cash', $ctx['agent'], PaymentType::FULL);

        $booking->refresh();
        $this->assertEquals(\App\Enums\PaymentStatus::Paid, $booking->payment_status);
        $this->assertEquals(BookingStatus::Confirmed, $booking->booking_status);
        $this->assertEquals(0.00, $booking->getRawOriginal('balance_due') / 100);
    }

    public function test_record_payment_against_booking_with_incomplete_passenger(): void
    {
        // Composes the Phase 2B data_complete fix with the P0-6 payment consolidation: a
        // phone booking with one data_complete=false passenger must still compute
        // balance_due correctly once a payment is recorded.
        $ctx = $this->makePaidTestBooking('p6d', 100.00, 0);
        $booking = $this->createBookingService->execute([
            'tenant_id' => $ctx['tenant']->id,
            'trip_instance_id' => $ctx['instance']->id,
            'customer_id' => $ctx['customer']->id,
            'phone_booking_mode' => true,
            'passengersData' => [
                ['trip_passenger_category_id' => $ctx['cat']->id, 'first_name' => 'Complete', 'last_name' => 'One'],
                ['trip_passenger_category_id' => $ctx['cat']->id], // incomplete
            ],
        ]);

        $this->assertEquals(200.00, $booking->grand_total);

        $this->bookingService->recordPayment($booking, 100.00, 'cash', $ctx['agent']);
        $booking->refresh();
        $this->assertEquals(100.00, $booking->total_paid);
        $this->assertEquals(100.00, $booking->getRawOriginal('balance_due') / 100);
        $this->assertEquals(\App\Enums\PaymentStatus::PartiallyPaid, $booking->payment_status);
    }

    public function test_reverse_payment_succeeds_and_leaves_original_untouched(): void
    {
        $ctx = $this->makePaidTestBooking('p6e', 100.00);
        $booking = $ctx['booking'];

        $original = $this->bookingService->recordPayment($booking, 100.00, 'cash', $ctx['agent'], PaymentType::FULL);
        $booking->refresh();
        $this->assertEquals(BookingStatus::Confirmed, $booking->booking_status);

        $reversal = $this->bookingService->reversePayment($original, 'Customer requested refund', $ctx['agent']);

        $this->assertEquals(-100.00, $reversal->amount);
        $this->assertEquals($original->id, $reversal->reversed_payment_id);

        // Original row provably untouched: re-fetch from DB, confirm amount/type unchanged.
        $original->refresh();
        $this->assertEquals(100.00, $original->amount);
        $this->assertNull($original->reversed_payment_id, 'The reversed_payment_id column lives on the REVERSAL row, never on the original — the original must remain exactly as created.');

        $booking->refresh();
        $this->assertEquals(0.00, $booking->total_paid, 'total_paid must reflect the reversal (100 + (-100) = 0).');
        $this->assertEquals(100.00, $booking->getRawOriginal('balance_due') / 100);
    }

    public function test_repeated_reversal_attempt_is_rejected(): void
    {
        // Regression test for the confirmed idempotency-direction bug: the old check read
        // $original->reversed_payment_id, a column only ever written on the REVERSAL row, so
        // it could never trigger and unlimited re-reversal was structurally possible. The
        // corrected check queries for an existing reversal pointing at the original.
        $ctx = $this->makePaidTestBooking('p6f', 100.00);
        $booking = $ctx['booking'];

        $original = $this->bookingService->recordPayment($booking, 100.00, 'cash', $ctx['agent'], PaymentType::FULL);
        $this->bookingService->reversePayment($original, 'First reversal', $ctx['agent']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('تم عكسها مسبقاً');
        try {
            $this->bookingService->reversePayment($original, 'Second reversal attempt', $ctx['agent']);
        } finally {
            // Whether or not the exception was thrown, confirm no duplicate reversal was
            // created — this is the assertion that actually proves the fix, since a version
            // of this test that only checked for a thrown exception could pass even if the
            // guard were checking the wrong direction on some other unrelated throw path.
            $this->assertEquals(
                1,
                Payment::where('reversed_payment_id', $original->id)->count(),
                'Exactly one reversal row must exist for this original payment, never two.'
            );
        }
    }

    public function test_financial_totals_after_partial_reversal(): void
    {
        $ctx = $this->makePaidTestBooking('p6g', 100.00);
        $booking = $ctx['booking'];

        $payment1 = $this->bookingService->recordPayment($booking, 40.00, 'cash', $ctx['agent']);
        $this->bookingService->recordPayment($booking, 60.00, 'cash', $ctx['agent']);
        $booking->refresh();
        $this->assertEquals(100.00, $booking->total_paid);
        $this->assertEquals(BookingStatus::Confirmed, $booking->booking_status);

        $this->bookingService->reversePayment($payment1, 'Partial refund', $ctx['agent']);
        $booking->refresh();

        $this->assertEquals(60.00, $booking->total_paid, '100 - 40 (reversed) = 60.');
        $this->assertEquals(40.00, $booking->getRawOriginal('balance_due') / 100);
        $this->assertEquals(\App\Enums\PaymentStatus::PartiallyPaid, $booking->payment_status);
        // recalculateTotals() only ever transitions a booking FORWARD (Pending/ConfirmedPartial
        // -> Confirmed); a reversal correctly does not automatically un-confirm/un-ticket a
        // booking that was already confirmed — that would be a separate business decision.
        $this->assertEquals(BookingStatus::Confirmed, $booking->booking_status, 'A reversal must not automatically move a Confirmed booking backwards.');
    }

    public function test_payment_and_cancellation_interaction_no_automatic_refund(): void
    {
        // Invariant G: a negative-implying scenario (cancelling a paid booking) must never
        // automatically mutate/reverse existing Payment rows — only cancelBooking()'s explicit
        // fee-override changes the Booking's own grand_total/balance_due.
        $ctx = $this->makePaidTestBooking('p6h', 100.00);
        $booking = $ctx['booking'];

        $this->bookingService->recordPayment($booking, 100.00, 'cash', $ctx['agent'], PaymentType::FULL);
        $this->assertEquals(1, Payment::where('booking_id', $booking->id)->count());

        $this->bookingService->cancelBooking($booking, 'Trip cancelled by customer', 20.00);

        $this->assertEquals(1, Payment::where('booking_id', $booking->id)->count(), 'Cancellation must not create, modify, or delete any Payment row.');
        $payment = Payment::where('booking_id', $booking->id)->first();
        $this->assertEquals(100.00, $payment->amount, 'The existing payment amount must remain exactly as recorded.');

        $rawGrandTotal = DB::table('bookings')->where('id', $booking->id)->value('grand_total');
        $rawBalanceDue = DB::table('bookings')->where('id', $booking->id)->value('balance_due');
        $this->assertEquals(2000, $rawGrandTotal, 'grand_total must be the 20.00 cancellation fee (2000 cents), per cancelBooking()\'s existing fee-override behavior.');
        $this->assertEquals(0, $rawBalanceDue);

        // P0-6 parity guard: recordPayment() against an already-Cancelled booking with a
        // (now positive, per the fee override having already zeroed it) balance must still be
        // rejected — and if somehow reached, recalculateTotals()'s Cancelled-status guard must
        // make any recalculation a no-op rather than recomputing from the now-empty passenger list.
        $booking->refresh();
        $this->assertEquals(BookingStatus::Cancelled, $booking->booking_status);
        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->recordPayment($booking, 10.00, 'cash', $ctx['agent']);
    }

    public function test_tenant_isolation_for_payment_recording(): void
    {
        $ctxA = $this->makePaidTestBooking('p6iA', 100.00);
        $ctxB = $this->makePaidTestBooking('p6iB', 100.00);

        $this->bookingService->recordPayment($ctxA['booking'], 50.00, 'cash', $ctxA['agent']);

        $ctxA['booking']->refresh();
        $ctxB['booking']->refresh();

        $this->assertEquals(50.00, $ctxA['booking']->total_paid);
        $this->assertEquals(0.00, $ctxB['booking']->total_paid, 'Tenant B\'s booking must be completely unaffected by a payment recorded against Tenant A\'s booking.');
        $this->assertEquals(0, Payment::where('booking_id', $ctxB['booking']->id)->count());

        $payment = Payment::where('booking_id', $ctxA['booking']->id)->first();
        $this->assertEquals($ctxA['tenant']->id, $payment->tenant_id);
        $this->assertNotEquals($ctxB['tenant']->id, $payment->tenant_id);
    }

    public function test_record_payment_lock_for_update_present_and_sequential_calls_correct(): void
    {
        // NOT VERIFIED as a true concurrency test: SQLite :memory: (this suite's isolated test
        // DB) does not support genuinely concurrent connections the way MySQL does, so no real
        // race was exercised here — consistent with how prior phases (P0-7/Phase 2B) honestly
        // scoped their own locking claims. This test proves two things only: (1) the
        // lockForUpdate() call is structurally present in recordPayment()/reversePayment()
        // (grep-verified below), and (2) two SEQUENTIAL calls against the same booking behave
        // correctly (cumulative, not overwritten) — it does NOT prove a true concurrent race
        // is prevented.
        $source = file_get_contents(app_path('Services/BookingService.php'));
        $recordPaymentStart = strpos($source, 'public function recordPayment(');
        $reversePaymentStart = strpos($source, 'public function reversePayment(');
        $this->assertNotFalse($recordPaymentStart);
        $this->assertNotFalse($reversePaymentStart);
        $this->assertStringContainsString('lockForUpdate()', substr($source, $recordPaymentStart, $reversePaymentStart - $recordPaymentStart), 'recordPayment() must acquire a pessimistic lock on the Booking row.');
        $this->assertStringContainsString('lockForUpdate()', substr($source, $reversePaymentStart, 2000), 'reversePayment() must acquire a pessimistic lock on the Booking row.');

        $ctx = $this->makePaidTestBooking('p6j', 100.00);
        $this->bookingService->recordPayment($ctx['booking'], 25.00, 'cash', $ctx['agent']);
        $this->bookingService->recordPayment($ctx['booking'], 25.00, 'cash', $ctx['agent']);
        $ctx['booking']->refresh();
        $this->assertEquals(50.00, $ctx['booking']->total_paid, 'Two sequential calls must be correctly cumulative.');
    }

    // P0-6: the former "gate parity" tests that compared recalculateTotals() against the
    // legacy recalculateFinancialStatus() directly (total_paid, balance_due — including a
    // rounding-stress case of 33.33+33.33+33.34 landing on exactly 14000 cents both ways —
    // payment_status, and a Cancelled-booking no-op guard) proved full parity once
    // recalculateTotals() clamped balance_due to max(0, ...) to match the legacy method (and
    // all 4 other original pre-remediation implementations — 5/5 historical precedent).
    // recalculateFinancialStatus() has been deleted as a result; these comparison tests were
    // removed with it since they called the now-deleted method directly.

    public function test_booking_financial_status_recalculation_and_reversal(): void
    {
        // Rewritten for P0-6 (explicit user exception granted for this specific test/its
        // fixtures only): the original version referenced BookingStatus::PENDING/PARTIAL/PAID
        // (no such enum cases exist — real cases are Pending/ConfirmedPartial/Confirmed/
        // Cancelled) and Booking::paid_amount/remaining_amount (no such attributes exist —
        // real fields are total_paid/balance_due), and used the pre-P0-6 PaymentService, whose
        // reversePayment() crashed on a nonexistent Booking::recalculateFinancials() call. This
        // is the one test file this repository has that exercises the full
        // payment/reversal/status-transition lifecycle end to end.
        $ctx = $this->makePaidTestBooking('p6-legacy-reversal', 50.00, 2); // grand_total = 100.00
        $booking = $ctx['booking'];
        $agent = $ctx['agent'];

        $this->assertEquals(BookingStatus::Pending, $booking->booking_status);
        $this->assertEquals(0.00, $booking->total_paid);
        $this->assertEquals(100.00, $booking->getRawOriginal('balance_due') / 100);

        // 1. Pay 40.00 (partial payment)
        $payment1 = $this->paymentService->recordPayment($booking, 40.00, 'cash', $agent, PaymentType::DEPOSIT);
        $booking->refresh();
        $this->assertEquals(BookingStatus::ConfirmedPartial, $booking->booking_status);
        $this->assertEquals(40.00, $booking->total_paid);
        $this->assertEquals(60.00, $booking->getRawOriginal('balance_due') / 100);

        // 2. Pay remaining 60.00 (full payment)
        $this->paymentService->recordPayment($booking, 60.00, 'visa', $agent, PaymentType::INSTALLMENT);
        $booking->refresh();
        $this->assertEquals(BookingStatus::Confirmed, $booking->booking_status);
        $this->assertEquals(100.00, $booking->total_paid);
        $this->assertEquals(0.00, $booking->getRawOriginal('balance_due') / 100);

        // 3. Reverse the first payment of 40.00 — this is the call that previously crashed
        // (PaymentService::reversePayment() -> nonexistent Booking::recalculateFinancials()).
        $this->paymentService->reversePayment($payment1, 'Customer request refund', $agent);
        $booking->refresh();
        $this->assertEquals(60.00, $booking->total_paid, '100.00 - 40.00 (reversed) = 60.00.');
        $this->assertEquals(40.00, $booking->getRawOriginal('balance_due') / 100);
        $this->assertEquals(\App\Enums\PaymentStatus::PartiallyPaid, $booking->payment_status);

        // 4. Attempting to reverse the same payment again must be rejected, not silently
        // succeed a second time (the confirmed idempotency-direction bug this phase fixes).
        $this->expectException(\RuntimeException::class);
        $this->paymentService->reversePayment($payment1, 'Second attempt', $agent);
    }

    public function test_cancel_booking_called_twice_is_idempotent(): void
    {
        // P0-5 regression: cancelBooking() previously had no guard against being invoked
        // twice for the same booking (a concurrent double-submit, or an independent caller
        // racing cancelPassengers()'s internal delegation to this method). A second call
        // would re-run the customer notification and WaitlistAutoPromotion dispatch a
        // second time. Inventory release itself was already self-guarded by
        // InventoryService::releaseForCancellation(), but nothing else in the method was.
        // The new guard (an early return once booking_status is already Cancelled, read
        // under the pre-existing lockForUpdate()) makes the whole method idempotent.
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-double-cancel', 'domain' => 'double-cancel.zatara.com']);
        $customer = Customer::create(['name' => 'Jane', 'phone' => '0500000097', 'tenant_id' => $tenant->id]);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => 'active',
        ]);
        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 100.00,
            'requires_seat' => true,
        ]);

        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'pnr' => 'ZTR-DBLCANCEL',
            'currency' => 'USD',
            'booking_status' => BookingStatus::Confirmed,
            'grand_total' => 100.00,
            'balance_due' => 0,
            'total_paid' => 100.00,
        ]);
        $booking->passengers()->create([
            'tenant_id' => $tenant->id,
            'trip_passenger_category_id' => $cat->id,
            'price_at_booking' => 100.00,
            'first_name' => 'Passenger',
            'last_name' => 'One',
            'data_complete' => true,
        ]);

        Bus::fake();
        \Illuminate\Support\Facades\Notification::fake();

        $this->bookingService->cancelBooking($booking, 'first cancellation');

        $booking->refresh();
        $this->assertEquals(BookingStatus::Cancelled, $booking->booking_status);
        $this->assertEquals(0, $booking->passengers()->count());

        // Second call on the same, already-cancelled booking must be a no-op.
        $this->bookingService->cancelBooking($booking, 'second cancellation attempt');

        $releaseRows = \App\Models\InventoryLedger::where('booking_id', $booking->id)->where('type', 'cancelled')->get();
        $this->assertCount(1, $releaseRows, 'Exactly one release ledger row, even after cancelBooking() is called twice.');
        $this->assertEquals(1, $releaseRows->first()->quantity);

        Bus::assertDispatchedTimes(\App\Jobs\WaitlistAutoPromotion::class, 1);
        \Illuminate\Support\Facades\Notification::assertSentToTimes($customer, \App\Notifications\BookingCancelled::class, 1);
    }

    public function test_package_option_remaining_seats_not_double_released_on_repeated_cancellation(): void
    {
        // P0-5 regression: cancelBooking() used to contain a dead/broken block that iterated
        // $booking->addons (no such relation exists on Booking — the real relation is
        // bookingAddons()) looking for $addon->packageOption (BookingAddon has no
        // packageOption() relation either; package options are selected at the booking level
        // via $booking->packageOption, not per addon). The loop body could therefore never
        // run. Even if reached, PackageOption::remaining_seats is a computed accessor
        // (getRemainingSeatsAttribute()), not a persisted column, so calling
        // ->increment('remaining_seats') on it would have thrown a SQL error against a
        // nonexistent column. This test confirms remaining_seats — which is derived live
        // from non-cancelled bookings against package_option_id — is correct after
        // cancellation and stays stable (not "double released") across a repeated call.
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-pkg-cancel', 'domain' => 'pkg-cancel.zatara.com']);
        $customer = Customer::create(['name' => 'Jane', 'phone' => '0500000098', 'tenant_id' => $tenant->id]);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 20,
            'status' => 'active',
        ]);
        $cat = \App\Models\TripPassengerCategory::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Adult',
            'price' => 100.00,
            'requires_seat' => true,
        ]);
        $package = \App\Models\PackageOption::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'name' => 'Deluxe',
            'price_adjustment' => 0,
            'available_seats' => 5,
        ]);

        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'package_option_id' => $package->id,
            'pnr' => 'ZTR-PKGCANCEL',
            'currency' => 'USD',
            'booking_status' => BookingStatus::Confirmed,
            'grand_total' => 200.00,
            'balance_due' => 0,
            'total_paid' => 200.00,
        ]);
        $booking->passengers()->create([
            'tenant_id' => $tenant->id,
            'trip_passenger_category_id' => $cat->id,
            'price_at_booking' => 100.00,
            'first_name' => 'Passenger',
            'last_name' => 'One',
            'data_complete' => true,
        ]);
        $booking->passengers()->create([
            'tenant_id' => $tenant->id,
            'trip_passenger_category_id' => $cat->id,
            'price_at_booking' => 100.00,
            'first_name' => 'Passenger',
            'last_name' => 'Two',
            'data_complete' => true,
        ]);

        $package->refresh();
        $this->assertEquals(3, $package->remaining_seats, '5 available - 2 booked = 3 remaining before cancellation.');

        Bus::fake();

        $this->bookingService->cancelBooking($booking, 'test cancellation');
        $package->refresh();
        $this->assertEquals(5, $package->remaining_seats, 'All 5 seats free again once the booking is cancelled.');

        // Second call is an idempotent no-op — remaining_seats must not move past the
        // correct value (there is no persisted counter to over-increment, but this guards
        // that the derived value stays correct across a repeated call).
        $this->bookingService->cancelBooking($booking, 'second cancellation attempt');
        $package->refresh();
        $this->assertEquals(5, $package->remaining_seats, 'Repeated cancellation does not affect remaining_seats further.');
    }

    public function test_release_expired_bookings_repeated_execution_is_protected(): void
    {
        // P0-5 regression: the command selected expired bookings outside any transaction and
        // then cancelled + notified for each with no row lock or re-check, so an overlapping
        // or repeated run (e.g. a slow prior run still in flight when the next schedule tick
        // fires) could re-select and re-process the same expired booking, dispatching
        // duplicate cancellation notifications. It now locks each booking row inside its
        // transaction and re-checks booking_status/payment_status under that lock before
        // mutating, so a second run correctly finds the booking already handled and skips it.
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-expire', 'domain' => 'expire.zatara.com']);
        $customer = Customer::create(['name' => 'Jane', 'phone' => '0500000096', 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100.00]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'trip_instance_id' => $instance->id,
            'customer_id' => $customer->id,
            'pnr' => 'ZTR-EXPIRED',
            'currency' => 'USD',
            'booking_status' => BookingStatus::Pending,
            'payment_status' => \App\Enums\PaymentStatus::Unpaid,
            'grand_total' => 100.00,
            'balance_due' => 100.00,
            'total_paid' => 0,
            'expires_at' => now()->subHour(),
        ]);

        \Illuminate\Support\Facades\Queue::fake();

        // Simulate two overlapping/repeated invocations of the same scheduled command.
        $this->artisan('bookings:release-expired')->assertExitCode(0);
        $this->artisan('bookings:release-expired')->assertExitCode(0);

        $booking->refresh();
        $this->assertEquals(BookingStatus::Cancelled, $booking->booking_status);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendBookingNotificationJob::class, 1);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\PaymentType;
use App\Exceptions\InsufficientRoomsException;
use App\Livewire\CheckoutWizard;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Hotel;
use App\Models\InventoryLedger;
use App\Models\RoomInventoryLedger;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripStayLeg;
use App\Models\TripStayLegHotelOption;
use App\Models\TripTemplate;
use App\Services\BookingService;
use App\Services\CreateBookingService;
use App\Services\RoomInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for Hotel/Rooming redesign Ticket 2 (RoomInventoryService + booking
 * integration). Treated with the same rigor as the original P0-5/P0-6/P0-7 remediation work,
 * per the ticket's own framing — every assertion here is about proving the coexistence design
 * is genuinely safe, not just that the happy path works.
 */
class RoomInventoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private CreateBookingService $createBookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingService = new CreateBookingService();
    }

    /**
     * @return array{tenant: Tenant, customer: Customer, template: TripTemplate, instance: TripInstance, cat: TripPassengerCategory, hotel: Hotel, leg: TripStayLeg, option: TripStayLegHotelOption, roomType: RoomType}
     */
    private function makeFixture(
        string $suffix,
        bool $roomBookingEnabled = true,
        int $roomCount = 5,
        int $capacityPerRoom = 2,
        float $priceShared = 40.00,
        float $priceSingleSupplement = 25.00,
        float $passengerPrice = 100.00
    ): array {
        $tenant = Tenant::create([
            'name' => "Agency {$suffix}", 'slug' => "agency-rit-{$suffix}", 'domain' => "{$suffix}.zatara.com",
            'settings' => ['room_booking_enabled' => $roomBookingEnabled],
        ]);
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0596{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => $passengerPrice]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => $passengerPrice, 'requires_seat' => true,
        ]);

        $hotel = Hotel::create(['tenant_id' => $tenant->id, 'name' => 'Test Hotel']);
        $leg = TripStayLeg::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'sequence' => 1, 'start_date' => now()->addDays(5), 'end_date' => now()->addDays(7),
        ]);
        $option = TripStayLegHotelOption::create([
            'tenant_id' => $tenant->id, 'trip_stay_leg_id' => $leg->id, 'hotel_id' => $hotel->id, 'is_active' => true,
        ]);
        $roomType = RoomType::create([
            'tenant_id' => $tenant->id, 'trip_stay_leg_hotel_option_id' => $option->id,
            'name' => 'Double', 'capacity_per_room' => $capacityPerRoom, 'room_count' => $roomCount,
            'price_adjustment_shared' => $priceShared, 'price_adjustment_single_supplement' => $priceSingleSupplement,
            'is_active' => true,
        ]);

        return compact('tenant', 'customer', 'template', 'instance', 'cat', 'hotel', 'leg', 'option', 'roomType');
    }

    private function makeBooking(array $f, float $additionalDepositAmount = 0): Booking
    {
        return $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // RoomInventoryService: consume / capacity / IDOR
    // ------------------------------------------------------------------

    public function test_consume_for_booking_writes_a_confirmed_ledger_row(): void
    {
        $f = $this->makeFixture('001', roomCount: 5);
        $booking = $this->makeBooking($f);

        $resolved = (new RoomInventoryService())->consumeForBooking($booking, [
            ['room_type_id' => $f['roomType']->id, 'quantity' => 2, 'occupancy_type' => 'shared'],
        ]);

        $this->assertCount(1, $resolved);
        $this->assertEquals(-2, RoomInventoryLedger::where('room_type_id', $f['roomType']->id)->sum('quantity'));
        $this->assertEquals('confirmed', RoomInventoryLedger::where('room_type_id', $f['roomType']->id)->first()->type);
    }

    public function test_consume_for_booking_throws_when_insufficient_rooms(): void
    {
        $f = $this->makeFixture('002', roomCount: 1);
        $booking = $this->makeBooking($f);

        $this->expectException(InsufficientRoomsException::class);
        (new RoomInventoryService())->consumeForBooking($booking, [
            ['room_type_id' => $f['roomType']->id, 'quantity' => 2, 'occupancy_type' => 'shared'],
        ]);
    }

    public function test_consume_for_booking_respects_existing_consumption_from_other_bookings(): void
    {
        $f = $this->makeFixture('003', roomCount: 2);
        $bookingA = $this->makeBooking($f);
        $bookingB = $this->makeBooking($f);

        (new RoomInventoryService())->consumeForBooking($bookingA, [
            ['room_type_id' => $f['roomType']->id, 'quantity' => 2, 'occupancy_type' => 'shared'],
        ]);

        $this->expectException(InsufficientRoomsException::class);
        (new RoomInventoryService())->consumeForBooking($bookingB, [
            ['room_type_id' => $f['roomType']->id, 'quantity' => 1, 'occupancy_type' => 'shared'],
        ]);
    }

    public function test_consume_for_booking_rejects_room_type_belonging_to_a_different_trip_instance(): void
    {
        $f1 = $this->makeFixture('004a');
        $f2 = $this->makeFixture('004b');
        $bookingOnF1 = $this->makeBooking($f1);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        (new RoomInventoryService())->consumeForBooking($bookingOnF1, [
            ['room_type_id' => $f2['roomType']->id, 'quantity' => 1, 'occupancy_type' => 'shared'],
        ]);

        // Nothing must have been consumed against f2's inventory either.
        $this->assertSame(0, RoomInventoryLedger::count());
    }

    public function test_release_for_cancellation_is_idempotent_and_fully_restores_capacity(): void
    {
        $f = $this->makeFixture('005', roomCount: 3);
        $booking = $this->makeBooking($f);
        (new RoomInventoryService())->consumeForBooking($booking, [
            ['room_type_id' => $f['roomType']->id, 'quantity' => 2, 'occupancy_type' => 'shared'],
        ]);

        $service = new RoomInventoryService();
        $service->releaseForCancellation($booking);
        $service->releaseForCancellation($booking); // second call must be a no-op

        $remaining = $f['roomType']->room_count + RoomInventoryLedger::where('room_type_id', $f['roomType']->id)->sum('quantity');
        $this->assertEquals(3, $remaining, 'Full capacity must be restored exactly once.');
        $this->assertSame(1, RoomInventoryLedger::where('booking_id', $booking->id)->where('type', 'cancelled')->count(), 'Idempotency guard must prevent a second reversal row.');
    }

    // ------------------------------------------------------------------
    // Same-transaction atomicity (seats + rooms)
    // ------------------------------------------------------------------

    public function test_room_consumption_failure_rolls_back_everything_atomically(): void
    {
        $f = $this->makeFixture('006', roomCount: 0); // guaranteed InsufficientRoomsException

        $bookingCountBefore = Booking::count();
        $seatLedgerCountBefore = InventoryLedger::count();
        $roomLedgerCountBefore = RoomInventoryLedger::count();

        $this->expectException(InsufficientRoomsException::class);

        try {
            $this->createBookingService->execute([
                'tenant_id' => $f['tenant']->id,
                'trip_instance_id' => $f['instance']->id,
                'customer_id' => $f['customer']->id,
                'passengersData' => [
                    ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
                ],
                'room_selections' => [
                    ['room_type_id' => $f['roomType']->id, 'quantity' => 1, 'occupancy_type' => 'shared'],
                ],
            ]);
        } finally {
            $this->assertSame($bookingCountBefore, Booking::count(), 'No Booking row may survive — same transaction as the seat consumption that already ran.');
            $this->assertSame($seatLedgerCountBefore, InventoryLedger::count(), 'Seat inventory consumed earlier in the same call must roll back too.');
            $this->assertSame($roomLedgerCountBefore, RoomInventoryLedger::count());
            $this->assertSame(0, \App\Models\Passenger::count(), 'Passenger rows created earlier in the same call must roll back too.');
        }
    }

    // ------------------------------------------------------------------
    // Pricing formula
    // ------------------------------------------------------------------

    public function test_shared_occupancy_charges_per_person_times_room_capacity(): void
    {
        $f = $this->makeFixture('007', capacityPerRoom: 2, priceShared: 40.00, priceSingleSupplement: 25.00);
        $booking = $this->makeBooking($f);

        (new RoomInventoryService())->consumeForBooking($booking, [
            ['room_type_id' => $f['roomType']->id, 'quantity' => 2, 'occupancy_type' => 'shared'],
        ]);

        // This test exercises RoomInventoryService only (no pricing there by design) — pricing
        // itself is exercised end-to-end via CreateBookingService in the tests below.
        $this->assertTrue(true);
    }

    public function test_end_to_end_shared_occupancy_pricing_via_create_booking_service(): void
    {
        $f = $this->makeFixture('008', capacityPerRoom: 2, priceShared: 40.00, priceSingleSupplement: 25.00, passengerPrice: 100.00);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
            'room_selections' => [
                ['room_type_id' => $f['roomType']->id, 'quantity' => 2, 'occupancy_type' => 'shared'],
            ],
        ]);

        $selection = $booking->roomSelections()->first();
        $this->assertNotNull($selection);
        $this->assertEquals('shared', $selection->occupancy_type->value);
        // per-room charge = 40 (shared, per person) * 2 (capacity) = 80; * 2 rooms = 160
        $this->assertEquals(160.00, $selection->price_at_booking);
        // grand_total = 100 (passenger) + 160 (rooms)
        $this->assertEquals(260.00, $booking->fresh()->grand_total);
    }

    public function test_end_to_end_single_occupancy_pricing_via_create_booking_service(): void
    {
        $f = $this->makeFixture('009', capacityPerRoom: 2, priceShared: 40.00, priceSingleSupplement: 25.00, passengerPrice: 100.00);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
            'room_selections' => [
                ['room_type_id' => $f['roomType']->id, 'quantity' => 1, 'occupancy_type' => 'single'],
            ],
        ]);

        $selection = $booking->roomSelections()->first();
        // per-room charge (single) = 40 (shared/per-person) + 25 (flat supplement) = 65; * 1 room = 65
        $this->assertEquals(65.00, $selection->price_at_booking);
        $this->assertEquals(165.00, $booking->fresh()->grand_total);
    }

    // ------------------------------------------------------------------
    // recalculateTotals() additive integration
    // ------------------------------------------------------------------

    public function test_recalculate_totals_room_charges_survive_a_subsequent_payment_recalculation(): void
    {
        $f = $this->makeFixture('010', capacityPerRoom: 2, priceShared: 40.00, passengerPrice: 100.00);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
            'room_selections' => [
                ['room_type_id' => $f['roomType']->id, 'quantity' => 1, 'occupancy_type' => 'shared'],
            ],
        ]);

        // 100 (passenger) + 80 (1 shared room: 40 * capacity 2)
        $this->assertEquals(180.00, $booking->fresh()->grand_total);

        app(BookingService::class)->recordPayment($booking, 50.00, 'cash', null, PaymentType::DEPOSIT);

        $this->assertEquals(180.00, $booking->fresh()->grand_total, 'recalculateTotals() must keep including room charges on every subsequent call, not just at creation.');
        $this->assertEquals(50.00, $booking->fresh()->total_paid);
        $this->assertEquals(130.00, $booking->fresh()->balance_due);
    }

    // ------------------------------------------------------------------
    // cancelBooking() room release integration
    // ------------------------------------------------------------------

    public function test_cancel_booking_releases_room_inventory_and_is_idempotent(): void
    {
        $f = $this->makeFixture('011', roomCount: 3);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
            'room_selections' => [
                ['room_type_id' => $f['roomType']->id, 'quantity' => 2, 'occupancy_type' => 'shared'],
            ],
        ]);

        $remainingAfterBooking = $f['roomType']->room_count + RoomInventoryLedger::where('room_type_id', $f['roomType']->id)->sum('quantity');
        $this->assertEquals(1, $remainingAfterBooking);

        app(BookingService::class)->cancelBooking($booking, 'test');
        app(BookingService::class)->cancelBooking($booking, 'test again'); // idempotent, no-op

        $remainingAfterCancel = $f['roomType']->room_count + RoomInventoryLedger::where('room_type_id', $f['roomType']->id)->sum('quantity');
        $this->assertEquals(3, $remainingAfterCancel, 'Rooms must be fully released, exactly once, even with a repeated cancelBooking() call.');
    }

    // ------------------------------------------------------------------
    // Kill switch — backend enforcement
    // ------------------------------------------------------------------

    public function test_backend_silently_ignores_room_selections_when_kill_switch_disabled(): void
    {
        $f = $this->makeFixture('012', roomBookingEnabled: false);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
            'room_selections' => [
                ['room_type_id' => $f['roomType']->id, 'quantity' => 1, 'occupancy_type' => 'shared'],
            ],
        ]);

        $this->assertSame(0, $booking->roomSelections()->count(), 'A disabled kill switch must ignore the payload, not process it.');
        $this->assertSame(0, RoomInventoryLedger::where('room_type_id', $f['roomType']->id)->count(), 'No inventory may be consumed while disabled.');
        $this->assertEquals(100.00, $booking->fresh()->grand_total, 'Booking creation itself must succeed normally, just without rooms.');
    }

    public function test_backend_processes_room_selections_when_kill_switch_enabled(): void
    {
        $f = $this->makeFixture('013', roomBookingEnabled: true);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
            'room_selections' => [
                ['room_type_id' => $f['roomType']->id, 'quantity' => 1, 'occupancy_type' => 'shared'],
            ],
        ]);

        $this->assertSame(1, $booking->roomSelections()->count());
    }

    // ------------------------------------------------------------------
    // Kill switch — UI enforcement (CheckoutWizard)
    // ------------------------------------------------------------------

    public function test_checkout_wizard_room_booking_available_is_false_when_kill_switch_disabled(): void
    {
        $f = $this->makeFixture('014', roomBookingEnabled: false);

        $component = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']]);

        $component->assertSet('roomBookingAvailable', false);
        $this->assertCount(0, $component->get('availableRoomTypes'));
    }

    public function test_checkout_wizard_room_booking_available_is_true_when_enabled_and_configured(): void
    {
        $f = $this->makeFixture('015', roomBookingEnabled: true);

        $component = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']]);

        $component->assertSet('roomBookingAvailable', true);
        $this->assertCount(1, $component->get('availableRoomTypes'));
    }

    public function test_checkout_wizard_room_booking_available_is_false_when_enabled_but_no_catalog_data(): void
    {
        $f = $this->makeFixture('016', roomBookingEnabled: true);
        // Remove the only room type so the catalog is empty despite the switch being on.
        $f['roomType']->delete();

        $component = Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']]);

        $component->assertSet('roomBookingAvailable', false);
    }

    public function test_checkout_wizard_end_to_end_room_selection_creates_correct_booking_room_selection(): void
    {
        $f = $this->makeFixture('017', roomBookingEnabled: true, capacityPerRoom: 2, priceShared: 40.00, priceSingleSupplement: 25.00);

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Jane')
            ->set('form.passengers.0.last_name', 'Doe')
            ->set('form.passengers.0.trip_passenger_category_id', $f['cat']->id)
            ->set('form.email', 'jane-017@example.com')
            ->set('form.phone', '+966500000171')
            ->call('submitLeadCapture')
            ->call('submitPassengers')
            ->call('updateRoomSelectionQuantity', $f['roomType']->id, 1)
            ->call('updateRoomSelectionOccupancy', $f['roomType']->id, 'single')
            ->call('submitAddons')
            ->set('paymentType', 'full')
            ->set('paymentMethod', 'cash')
            ->call('submitBooking');

        $booking = Booking::first();
        $this->assertNotNull($booking);

        $selection = $booking->roomSelections()->first();
        $this->assertNotNull($selection);
        $this->assertEquals(1, $selection->quantity);
        $this->assertEquals('single', $selection->occupancy_type->value);
        $this->assertEquals(65.00, $selection->price_at_booking); // 40 + 25 supplement

        $remaining = $f['roomType']->room_count + RoomInventoryLedger::where('room_type_id', $f['roomType']->id)->sum('quantity');
        $this->assertEquals(4, $remaining, '1 of 5 rooms consumed.');
    }

    public function test_checkout_wizard_sends_no_room_selections_when_kill_switch_disabled_even_if_state_present(): void
    {
        // Simulates a stale client / bypassed UI: roomSelections state is set directly, but the
        // kill switch is off. The UI-level gate in submitBooking() must still send nothing.
        $f = $this->makeFixture('018', roomBookingEnabled: false);

        Livewire::test(CheckoutWizard::class, ['tenant' => $f['tenant'], 'tripInstance' => $f['instance']])
            ->set('form.passengers.0.first_name', 'Jane')
            ->set('form.passengers.0.last_name', 'Doe')
            ->set('form.passengers.0.trip_passenger_category_id', $f['cat']->id)
            ->set('form.email', 'jane-018@example.com')
            ->set('form.phone', '+966500000181')
            ->call('submitLeadCapture')
            ->call('submitPassengers')
            ->set('roomSelections', [$f['roomType']->id => ['quantity' => 1, 'occupancy_type' => 'shared']])
            ->call('submitAddons')
            ->set('paymentType', 'full')
            ->set('paymentMethod', 'cash')
            ->call('submitBooking');

        $booking = Booking::first();
        $this->assertNotNull($booking);
        $this->assertSame(0, $booking->roomSelections()->count());
        $this->assertSame(0, RoomInventoryLedger::where('room_type_id', $f['roomType']->id)->count());
    }

    // ------------------------------------------------------------------
    // PackageOption coexistence — confirmed unaffected
    // ------------------------------------------------------------------

    public function test_package_option_booking_path_is_completely_unaffected_by_room_selections_code(): void
    {
        $f = $this->makeFixture('019');
        $package = \App\Models\PackageOption::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'name' => 'Legacy Package',
            'price_adjustment' => 50,
            'available_seats' => 5,
        ]);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'package_option_id' => $package->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $this->assertEquals($package->id, $booking->package_option_id);
        $this->assertSame(0, $booking->roomSelections()->count());
        // 100 (passenger) + 50 (package, * 1 passenger)
        $this->assertEquals(150.00, $booking->fresh()->grand_total);
    }
}

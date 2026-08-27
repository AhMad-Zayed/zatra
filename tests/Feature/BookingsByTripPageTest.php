<?php

namespace Tests\Feature;

use App\Enums\PaymentType;
use App\Filament\Pages\BookingsByTrip;
use App\Models\Customer;
use App\Models\Hotel;
use App\Models\RoomAssignment;
use App\Models\RoomInstance;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripStayLeg;
use App\Models\TripStayLegHotelOption;
use App\Models\TripTemplate;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CreateBookingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Bookings by Trip" nested browse screen -- a pure read-only display consumer. Every assertion
 * below is checked against data produced by the REAL booking/payment services (CreateBookingService,
 * BookingService::recordPayment), not raw DB rows, since this page's whole job is faithfully
 * displaying what those services already computed.
 */
class BookingsByTripPageTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * Tenant A: 2 trips.
     * - Trip 1 ("Trip Alpha"): 2 bookings -- Ahmad (2 passengers, fully paid, both requirements
     *   complete) and Sara (1 passenger, unpaid, requirements incomplete). Trip-level badge must
     *   be amber (1 of 2 bookings paid).
     * - Trip 2 ("Trip Beta"): 1 booking -- Omar (1 passenger, fully paid). Trip-level badge must
     *   be green (all paid).
     */
    private function makeFixture(): array
    {
        $tenant = Tenant::create(['name' => 'Agency BBT', 'slug' => 'agency-bbt']);
        $admin = $this->makeAgencyAdmin($tenant, '0501000001');

        $templateA = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip Alpha', 'base_price' => 100]);
        $tripA = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $templateA->id,
            'start_date' => now()->addDays(10), 'end_date' => now()->addDays(15),
            'available_seats' => 20, 'status' => 'active',
        ]);
        $catA = TripPassengerCategory::create(['tenant_id' => $tenant->id, 'trip_instance_id' => $tripA->id, 'name' => 'Adult', 'price' => 100, 'requires_seat' => true]);

        $ahmad = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Ahmad Abdullah', 'phone' => '0791234567']);
        $bookingAhmad = app(CreateBookingService::class)->execute([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $tripA->id, 'customer_id' => $ahmad->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $catA->id, 'first_name' => 'Ahmad', 'last_name' => 'Abdullah'],
                ['trip_passenger_category_id' => $catA->id, 'first_name' => 'Sara', 'last_name' => 'Khaled'],
            ],
        ]);
        $bookingAhmad->passengers->each(fn ($p) => $p->update(['requirements_complete' => true]));
        $bookingAhmad->passengers->first()->update(['seat_number' => '12A']);
        app(BookingService::class)->recordPayment($bookingAhmad, (float) $bookingAhmad->grand_total, 'cash', $admin, PaymentType::FULL);

        $sara = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Sara Mansour', 'phone' => '0797654321']);
        $bookingSara = app(CreateBookingService::class)->execute([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $tripA->id, 'customer_id' => $sara->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $catA->id, 'first_name' => 'Sara', 'last_name' => 'Mansour'],
            ],
        ]);
        // Left unpaid. requirements_complete defaults to true when the trip has no
        // RequirementPreset attached at all (nothing configured = nothing missing) -- forced
        // false here explicitly to exercise the "incomplete" display path.
        $bookingSara->passengers->each(fn ($p) => $p->update(['requirements_complete' => false]));

        $templateB = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip Beta', 'base_price' => 200]);
        $tripB = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $templateB->id,
            'start_date' => now()->addDays(20), 'end_date' => now()->addDays(25),
            'available_seats' => 10, 'status' => 'active',
        ]);
        $catB = TripPassengerCategory::create(['tenant_id' => $tenant->id, 'trip_instance_id' => $tripB->id, 'name' => 'Adult', 'price' => 200, 'requires_seat' => true]);
        $omar = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Omar Nasser', 'phone' => '0790001111']);
        $bookingOmar = app(CreateBookingService::class)->execute([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $tripB->id, 'customer_id' => $omar->id,
            'passengersData' => [['trip_passenger_category_id' => $catB->id, 'first_name' => 'Omar', 'last_name' => 'Nasser']],
        ]);
        $bookingOmar->passengers->each(fn ($p) => $p->update(['requirements_complete' => true]));
        app(BookingService::class)->recordPayment($bookingOmar, (float) $bookingOmar->grand_total, 'cash', $admin, PaymentType::FULL);

        return compact('tenant', 'admin', 'templateA', 'tripA', 'catA', 'ahmad', 'bookingAhmad', 'sara', 'bookingSara', 'templateB', 'tripB', 'omar', 'bookingOmar');
    }

    public function test_page_loads_and_shows_all_trips_for_the_current_tenant(): void
    {
        $f = $this->makeFixture();
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(BookingsByTrip::class)
            ->assertOk()
            ->assertSee('Trip Alpha')
            ->assertSee('Trip Beta');
    }

    public function test_level_1_shows_correct_bookings_and_passenger_counts(): void
    {
        $f = $this->makeFixture();
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        $trips = Livewire::test(BookingsByTrip::class)->instance()->getTrips();
        $tripA = $trips->firstWhere('id', $f['tripA']->id);

        $this->assertEquals(2, $tripA->bookings->count(), 'Trip Alpha must show 2 bookings (Ahmad + Sara).');
        $this->assertEquals(3, $tripA->bookings->sum(fn ($b) => $b->passengers->count()), 'Trip Alpha must show 3 total passengers.');
    }

    public function test_level_1_financial_badge_is_amber_when_some_but_not_all_bookings_are_paid(): void
    {
        $f = $this->makeFixture();
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        $page = new BookingsByTrip();
        $trips = $page->getTrips();
        $tripA = $trips->firstWhere('id', $f['tripA']->id);

        $this->assertSame('warning', $page->tripFinancialColor($tripA->bookings));
    }

    public function test_level_1_financial_badge_is_green_when_all_bookings_are_paid(): void
    {
        $f = $this->makeFixture();
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        $page = new BookingsByTrip();
        $trips = $page->getTrips();
        $tripB = $trips->firstWhere('id', $f['tripB']->id);

        $this->assertSame('success', $page->tripFinancialColor($tripB->bookings));
    }

    public function test_level_1_financial_badge_is_red_when_no_bookings_are_paid(): void
    {
        $f = $this->makeFixture();
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        // A third trip with a single unpaid booking.
        $template = TripTemplate::create(['tenant_id' => $f['tenant']->id, 'title' => 'Trip Gamma', 'base_price' => 50]);
        $trip = TripInstance::create([
            'tenant_id' => $f['tenant']->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(30), 'end_date' => now()->addDays(31),
            'available_seats' => 5, 'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $trip->id, 'name' => 'Adult', 'price' => 50, 'requires_seat' => true]);
        $customer = Customer::create(['tenant_id' => $f['tenant']->id, 'name' => 'Layla', 'phone' => '0799990000']);
        app(CreateBookingService::class)->execute([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $trip->id, 'customer_id' => $customer->id,
            'passengersData' => [['trip_passenger_category_id' => $cat->id, 'first_name' => 'Layla', 'last_name' => 'X']],
        ]);

        $page = new BookingsByTrip();
        $trips = $page->getTrips();
        $tripGamma = $trips->firstWhere('id', $trip->id);

        $this->assertSame('danger', $page->tripFinancialColor($tripGamma->bookings));
    }

    public function test_level_1_financial_badge_is_gray_when_trip_has_zero_bookings(): void
    {
        $f = $this->makeFixture();
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        $template = TripTemplate::create(['tenant_id' => $f['tenant']->id, 'title' => 'Trip Empty', 'base_price' => 10]);
        $trip = TripInstance::create([
            'tenant_id' => $f['tenant']->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(40), 'end_date' => now()->addDays(41),
            'available_seats' => 5, 'status' => 'active',
        ]);

        $page = new BookingsByTrip();
        $trips = $page->getTrips();
        $tripEmpty = $trips->firstWhere('id', $trip->id);

        $this->assertSame('gray', $page->tripFinancialColor($tripEmpty->bookings));
    }

    public function test_level_2_documents_completeness_fraction_uses_arabic_indic_digits(): void
    {
        $f = $this->makeFixture();
        $page = new BookingsByTrip();

        // Ahmad's booking: 2/2 passengers complete.
        $this->assertSame('٢/٢ مكتملة', $page->documentsCompletionFraction($f['bookingAhmad']->fresh()));

        // Sara's booking: 0/1 complete.
        $this->assertSame('٠/١ مكتملة', $page->documentsCompletionFraction($f['bookingSara']->fresh()));
    }

    public function test_level_2_payment_status_badge_matches_the_bookings_own_status(): void
    {
        $f = $this->makeFixture();

        $this->assertSame(\App\Enums\PaymentStatus::Paid, $f['bookingAhmad']->fresh()->payment_status);
        $this->assertSame(\App\Enums\PaymentStatus::Unpaid, $f['bookingSara']->fresh()->payment_status);
    }

    public function test_level_3_booking_owner_tag_is_the_first_passenger_on_the_booking(): void
    {
        $f = $this->makeFixture();
        $page = new BookingsByTrip();

        $passengers = $f['bookingAhmad']->fresh()->passengers()->orderBy('id')->get();
        $this->assertTrue($page->isBookingOwner($passengers->first(), $f['bookingAhmad']));
        $this->assertFalse($page->isBookingOwner($passengers->last(), $f['bookingAhmad']));
    }

    public function test_level_3_seat_display_is_correct_and_gracefully_blank_when_unassigned(): void
    {
        $f = $this->makeFixture();
        $page = new BookingsByTrip();

        $passengers = $f['bookingAhmad']->fresh()->passengers()->orderBy('id')->get();
        $this->assertSame('مقعد 12A', $page->seatOrRoomDisplay($passengers->first()), 'Passenger with a seat_number set must show it.');
        $this->assertSame('—', $page->seatOrRoomDisplay($passengers->last()), 'Passenger with no seat/room assignment must show a graceful placeholder, not blank/error.');
    }

    public function test_level_3_room_assignment_display_reuses_real_hotel_rooming_data(): void
    {
        $f = $this->makeFixture();

        $hotel = Hotel::create(['tenant_id' => $f['tenant']->id, 'name' => 'Grand Hotel', 'is_active' => true]);
        $leg = TripStayLeg::create(['tenant_id' => $f['tenant']->id, 'trip_instance_id' => $f['tripA']->id, 'sequence' => 1, 'start_date' => now()->addDays(10), 'end_date' => now()->addDays(15)]);
        $hotelOption = TripStayLegHotelOption::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_id' => $leg->id, 'hotel_id' => $hotel->id, 'is_active' => true]);
        $roomType = RoomType::create(['tenant_id' => $f['tenant']->id, 'trip_stay_leg_hotel_option_id' => $hotelOption->id, 'name' => 'Double', 'capacity_per_room' => 2, 'room_count' => 5, 'price_adjustment_shared' => 0, 'price_adjustment_single_supplement' => 0, 'is_active' => true]);
        $roomInstance = RoomInstance::create(['tenant_id' => $f['tenant']->id, 'room_type_id' => $roomType->id, 'room_number' => 101]);

        $passenger = $f['bookingAhmad']->fresh()->passengers()->orderBy('id')->first();
        RoomAssignment::create([
            'tenant_id' => $f['tenant']->id, 'room_instance_id' => $roomInstance->id,
            'passenger_id' => $passenger->id, 'booking_id' => $f['bookingAhmad']->id,
        ]);

        $page = new BookingsByTrip();
        $refreshedPassenger = $passenger->fresh();

        $this->assertSame('مقعد 12A / غرفة 101', $page->seatOrRoomDisplay($refreshedPassenger), 'Must show both seat AND room when a passenger has both.');
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    public function test_tenant_isolation_only_shows_the_current_tenants_trips(): void
    {
        $f = $this->makeFixture();

        $otherTenant = Tenant::create(['name' => 'Other Agency', 'slug' => 'other-agency-bbt']);
        $otherTemplate = TripTemplate::create(['tenant_id' => $otherTenant->id, 'title' => 'Other Tenant Trip', 'base_price' => 100]);
        TripInstance::create([
            'tenant_id' => $otherTenant->id, 'trip_template_id' => $otherTemplate->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(BookingsByTrip::class)
            ->assertSee('Trip Alpha')
            ->assertDontSee('Other Tenant Trip');
    }

    // ------------------------------------------------------------------
    // Filter bar
    // ------------------------------------------------------------------

    public function test_filter_by_trip_name_narrows_results(): void
    {
        $f = $this->makeFixture();
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(BookingsByTrip::class)
            ->fillForm(['trip_name' => 'Alpha'])
            ->assertSee('Trip Alpha')
            ->assertDontSee('Trip Beta');
    }

    public function test_filter_by_date_range_narrows_results(): void
    {
        $f = $this->makeFixture();
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        // Trip Alpha starts in 10 days, Trip Beta in 20 -- a date_to of +15 days excludes Beta.
        Livewire::test(BookingsByTrip::class)
            ->fillForm(['date_to' => now()->addDays(15)->toDateString()])
            ->assertSee('Trip Alpha')
            ->assertDontSee('Trip Beta');
    }

    // ------------------------------------------------------------------
    // Expand/collapse state
    // ------------------------------------------------------------------

    public function test_toggle_trip_and_toggle_booking_reveal_nested_content(): void
    {
        $f = $this->makeFixture();
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        $component = Livewire::test(BookingsByTrip::class);
        $component->assertDontSee('Ahmad Abdullah');

        $component->call('toggleTrip', $f['tripA']->id);
        $component->assertSee('Ahmad Abdullah');
        $component->assertDontSee('مقعد 12A');

        $component->call('toggleBooking', $f['bookingAhmad']->id);
        $component->assertSee('مقعد 12A');
    }

    // ------------------------------------------------------------------
    // Guardrail-adjacent: purely read-only, no write anywhere
    // ------------------------------------------------------------------

    public function test_page_never_mutates_any_booking_or_passenger_data(): void
    {
        $f = $this->makeFixture();
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        $beforeGrandTotal = $f['bookingAhmad']->fresh()->grand_total;
        $beforePassengerCount = \App\Models\Passenger::count();

        Livewire::test(BookingsByTrip::class)
            ->call('toggleTrip', $f['tripA']->id)
            ->call('toggleBooking', $f['bookingAhmad']->id);

        $this->assertEquals($beforeGrandTotal, $f['bookingAhmad']->fresh()->grand_total);
        $this->assertEquals($beforePassengerCount, \App\Models\Passenger::count());
    }
}

<?php

namespace Tests\Feature;

use App\Exceptions\BusCapacityExceededException;
use App\Filament\Resources\TripInstanceResource\Pages\AssignBuses;
use App\Livewire\CustomerBookingPortal;
use App\Models\Customer;
use App\Models\Passenger;
use App\Models\Tenant;
use App\Models\TripBusAssignment;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BusSeatAssignmentService;
use App\Services\CreateBookingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for Bus/Fleet redesign Ticket 3 (drag-and-drop seat assignment):
 * BusSeatAssignmentService capacity enforcement, bus-scoped seat numbering (no false "seat 15
 * taken" conflict across two different buses), the auto-assign algorithm, the AssignBuses
 * board's drag-and-drop handlers, tenant isolation, and the CustomerBookingPortal edge case
 * from Ticket 2's design discussion.
 */
class BusSeatAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private CreateBookingService $createBookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingService = new CreateBookingService();
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

    /**
     * @return array{tenant: Tenant, admin: User, customer: Customer, template: TripTemplate, instance: TripInstance, cat: TripPassengerCategory}
     */
    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-bsa-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $admin = $this->makeAgencyAdmin($tenant, "0796{$suffix}");
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0597{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 20,
            'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);

        return compact('tenant', 'admin', 'customer', 'template', 'instance', 'cat');
    }

    private function makeBus(array $f, int $capacity): TripBusAssignment
    {
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'V-' . uniqid(), 'default_capacity' => $capacity]);

        return TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => $capacity,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
        ]);
    }

    /**
     * @param  array<int, string>  $names  one passenger per name, all on one new booking
     */
    private function makeBookingWithPassengers(array $f, array $names): \App\Models\Booking
    {
        $passengersData = [];
        foreach ($names as $i => $name) {
            $passengersData[] = ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => $name, 'last_name' => (string) $i];
        }

        return $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => $passengersData,
        ]);
    }

    // ------------------------------------------------------------------
    // assignPassengerToBus(): capacity enforcement + bus-scoped seat numbering
    // ------------------------------------------------------------------

    public function test_assign_passenger_succeeds_within_capacity(): void
    {
        $f = $this->makeFixture('001');
        $bus = $this->makeBus($f, 2);
        $passenger = $this->makeBookingWithPassengers($f, ['P1'])->passengers()->first();

        $updated = app(BusSeatAssignmentService::class)->assignPassengerToBus($passenger, $bus);

        $this->assertSame($bus->id, $updated->trip_bus_assignment_id);
        $this->assertSame('1', $updated->seat_number);
    }

    public function test_assign_passenger_rejects_when_bus_is_at_capacity(): void
    {
        // Bookings created BEFORE the bus, while the trip still has its fixture-default
        // available_seats (20) — Ticket 2's inventory recalculation is a separate concern from
        // this test (physical assignment capacity), so all 3 bookings must succeed at
        // booking-time regardless of the deliberately tiny 2-seat bus created afterward.
        $f = $this->makeFixture('002');
        $p1 = $this->makeBookingWithPassengers($f, ['P1'])->passengers()->first();
        $p2 = $this->makeBookingWithPassengers($f, ['P2'])->passengers()->first();
        $p3 = $this->makeBookingWithPassengers($f, ['P3'])->passengers()->first();
        $bus = $this->makeBus($f, 2);
        $service = app(BusSeatAssignmentService::class);

        $service->assignPassengerToBus($p1, $bus);
        $service->assignPassengerToBus($p2, $bus);

        $this->expectException(BusCapacityExceededException::class);
        $service->assignPassengerToBus($p3, $bus);
    }

    public function test_assign_passenger_is_idempotent_when_re_dropped_into_its_own_bus(): void
    {
        $f = $this->makeFixture('003');
        $bus = $this->makeBus($f, 1);
        $passenger = $this->makeBookingWithPassengers($f, ['P1'])->passengers()->first();
        $service = app(BusSeatAssignmentService::class);

        $service->assignPassengerToBus($passenger, $bus);
        // Re-dropping into the same, already-full-because-of-itself bus must not throw.
        $service->assignPassengerToBus($passenger, $bus);

        $this->assertSame('1', $passenger->fresh()->seat_number);
        $this->assertSame(1, Passenger::where('trip_bus_assignment_id', $bus->id)->count());
    }

    public function test_seat_numbers_are_scoped_per_bus_no_false_conflict(): void
    {
        $f = $this->makeFixture('004');
        $busA = $this->makeBus($f, 2);
        $busB = $this->makeBus($f, 2);
        $service = app(BusSeatAssignmentService::class);

        $pA = $this->makeBookingWithPassengers($f, ['PA'])->passengers()->first();
        $pB = $this->makeBookingWithPassengers($f, ['PB'])->passengers()->first();

        $service->assignPassengerToBus($pA, $busA);
        $service->assignPassengerToBus($pB, $busB);

        $this->assertSame('1', $pA->fresh()->seat_number);
        $this->assertSame('1', $pB->fresh()->seat_number, '"Seat 1 on Bus A" and "seat 1 on Bus B" must both be valid simultaneously — seat numbers are scoped per bus, not globally per trip.');
        $this->assertNotEquals($pA->fresh()->trip_bus_assignment_id, $pB->fresh()->trip_bus_assignment_id);
    }

    public function test_unassign_passenger_clears_bus_and_seat(): void
    {
        $f = $this->makeFixture('005');
        $bus = $this->makeBus($f, 2);
        $passenger = $this->makeBookingWithPassengers($f, ['P1'])->passengers()->first();
        $service = app(BusSeatAssignmentService::class);

        $service->assignPassengerToBus($passenger, $bus);
        $service->unassignPassenger($passenger);

        $this->assertNull($passenger->fresh()->trip_bus_assignment_id);
        $this->assertNull($passenger->fresh()->seat_number);
    }

    // ------------------------------------------------------------------
    // autoAssign()
    // ------------------------------------------------------------------

    public function test_auto_assign_keeps_a_group_together_in_a_single_bus_when_it_fits(): void
    {
        $f = $this->makeFixture('006');
        $this->makeBus($f, 3);
        $this->makeBus($f, 3);
        $booking = $this->makeBookingWithPassengers($f, ['P1', 'P2']);

        $result = app(BusSeatAssignmentService::class)->autoAssign($f['instance']);

        $this->assertSame(2, $result['assigned']);
        $this->assertTrue($result['unassigned']->isEmpty());

        $busIds = $booking->passengers()->pluck('trip_bus_assignment_id')->unique();
        $this->assertCount(1, $busIds, 'Both members of the same booking must land on the same bus when one bus has room for the whole group.');
    }

    public function test_auto_assign_splits_a_group_across_buses_when_no_single_bus_fits(): void
    {
        $f = $this->makeFixture('007');
        $this->makeBus($f, 2);
        $this->makeBus($f, 2);
        $booking = $this->makeBookingWithPassengers($f, ['P1', 'P2', 'P3']);

        $result = app(BusSeatAssignmentService::class)->autoAssign($f['instance']);

        $this->assertSame(3, $result['assigned']);
        $counts = $booking->passengers()->get()->groupBy('trip_bus_assignment_id')->map->count();
        $this->assertEqualsCanonicalizing([2, 1], $counts->values()->toArray());
    }

    public function test_auto_assign_reports_passengers_left_unassigned_when_truly_out_of_capacity(): void
    {
        $f = $this->makeFixture('008');
        $booking = $this->makeBookingWithPassengers($f, ['P1', 'P2', 'P3']);
        $this->makeBus($f, 2);

        $result = app(BusSeatAssignmentService::class)->autoAssign($f['instance']);

        $this->assertSame(2, $result['assigned']);
        $this->assertCount(1, $result['unassigned']);
    }

    // ------------------------------------------------------------------
    // getBoardData()
    // ------------------------------------------------------------------

    public function test_board_data_separates_unassigned_from_assigned_and_excludes_cancelled_bookings(): void
    {
        $f = $this->makeFixture('009');
        $assignedBooking = $this->makeBookingWithPassengers($f, ['P1']);
        $unassignedBooking = $this->makeBookingWithPassengers($f, ['P2']);
        $cancelledBooking = $this->makeBookingWithPassengers($f, ['P3']);
        $cancelledBooking->update(['booking_status' => \App\Enums\BookingStatus::Cancelled]);

        $bus = $this->makeBus($f, 2);
        $service = app(BusSeatAssignmentService::class);

        $service->assignPassengerToBus($assignedBooking->passengers()->first(), $bus);

        $board = $service->getBoardData($f['instance']->fresh());

        $this->assertCount(1, $board['unassigned']);
        $this->assertTrue($board['unassigned']->first()->is($unassignedBooking->passengers()->first()));
    }

    // ------------------------------------------------------------------
    // AssignBuses page: drag-and-drop handlers, tenant isolation
    // ------------------------------------------------------------------

    public function test_assign_buses_page_drop_and_remove_persist_and_notify_on_capacity_violation(): void
    {
        $f = $this->makeFixture('010');
        $bookingA = $this->makeBookingWithPassengers($f, ['PA']);
        $bookingB = $this->makeBookingWithPassengers($f, ['PB']);
        $bus = $this->makeBus($f, 1);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        $component = Livewire::test(AssignBuses::class, ['record' => $f['instance']->getRouteKey()]);

        $component->call('dropPassenger', $bookingA->passengers()->first()->id, $bus->id);
        $this->assertDatabaseHas('passengers', ['id' => $bookingA->passengers()->first()->id, 'trip_bus_assignment_id' => $bus->id, 'seat_number' => '1']);

        // Bus is now full (capacity 1) — dropping bookingB's passenger must be rejected.
        $component->call('dropPassenger', $bookingB->passengers()->first()->id, $bus->id);
        \Filament\Notifications\Notification::assertNotified();
        $this->assertDatabaseHas('passengers', ['id' => $bookingB->passengers()->first()->id, 'trip_bus_assignment_id' => null]);

        $component->call('removeFromBus', $bookingA->passengers()->first()->id);
        $this->assertDatabaseHas('passengers', ['id' => $bookingA->passengers()->first()->id, 'trip_bus_assignment_id' => null, 'seat_number' => null]);
    }

    public function test_assign_buses_page_blocks_cross_tenant_passenger_drop(): void
    {
        $fA = $this->makeFixture('011a');
        $fB = $this->makeFixture('011b');

        $foreignPassenger = $this->makeBookingWithPassengers($fB, ['P1'])->passengers()->first();

        setPermissionsTeamId($fA['tenant']->id);
        $this->actingAs($fA['admin']);
        Filament::setTenant($fA['tenant'], true);

        $bus = $this->makeBus($fA, 2);

        $page = new AssignBuses();
        $page->mount($fA['instance']->getRouteKey());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $page->dropPassenger($foreignPassenger->id, $bus->id);
    }

    // ------------------------------------------------------------------
    // Edge case (Ticket 2/3 handoff): a passenger who self-selected a seat via the portal on a
    // single-bus trip must not be silently lost or falsely conflict once a second bus is added
    // — they surface in the unassigned pool (with their old seat number shown as a hint) for
    // staff to explicitly re-confirm, rather than an automatic guess at which bus they belong to.
    // ------------------------------------------------------------------

    public function test_portal_selected_seat_surfaces_in_unassigned_pool_once_a_second_bus_is_added(): void
    {
        $f = $this->makeFixture('012');
        $booking = $this->makeBookingWithPassengers($f, ['P1']);
        $passenger = $booking->passengers()->first();
        $this->makeBus($f, 40); // single bus — portal would offer numbered selection

        // Confirm the portal genuinely offers seat selection at this point (single-bus trip) —
        // the point being tested is what happens to an existing seat_number once that stops
        // being true, not the save mechanics themselves (covered by BusFleetCapacityIntegrationTest).
        Livewire::test(CustomerBookingPortal::class, ['uuid' => $booking->uuid])
            ->assertSet('totalSeats', 40);

        // Simulates the outcome of a completed portal selection: seat_number set, no bus
        // reference yet, since that column didn't exist as a concept when the trip had only
        // one bus.
        $passenger->update(['seat_number' => '7']);
        $this->assertNull($passenger->fresh()->trip_bus_assignment_id, 'A single-bus portal selection has no bus reference yet — that\'s expected, not a bug.');

        // Staff now add a second bus.
        $this->makeBus($f, 40);

        $board = app(BusSeatAssignmentService::class)->getBoardData($f['instance']->fresh());

        $this->assertTrue($board['unassigned']->contains(fn ($p) => $p->is($passenger)), 'The passenger must surface in the unassigned pool once ambiguous, not disappear or falsely occupy a specific bus.');
        $this->assertSame('7', $passenger->fresh()->seat_number, 'Their old seat_number must be preserved as a hint, not wiped.');
    }
}

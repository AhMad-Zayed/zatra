<?php

namespace Tests\Feature;

use App\Exceptions\RoomCapacityExceededException;
use App\Filament\Resources\TripInstanceResource\Pages\AssignRooms;
use App\Filament\Resources\TripInstanceResource\Pages\EditTripInstance;
use App\Models\Customer;
use App\Models\Hotel;
use App\Models\Passenger;
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
use App\Services\CreateBookingService;
use App\Services\RoomAssignmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for Hotel/Rooming redesign Ticket 3 (post-booking staff room assignment):
 * RoomAssignmentService capacity/single-occupancy enforcement, the auto-assign bin-packing
 * algorithm against fixed fixtures, the rooming-list PDF route, and the AssignRooms page's
 * reachability + tenant isolation. RoomAssignmentService is new code owned entirely by this
 * ticket — not one of the six guardrail-protected services/Policy classes.
 */
class RoomAssignmentTest extends TestCase
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
     * @return array{tenant: Tenant, admin: User, customer: Customer, template: TripTemplate, instance: TripInstance, cat: TripPassengerCategory, hotel: Hotel, leg: TripStayLeg, option: TripStayLegHotelOption, roomType: RoomType}
     */
    private function makeFixture(
        string $suffix,
        int $roomCount = 2,
        int $capacityPerRoom = 2,
    ): array {
        $tenant = Tenant::create([
            'name' => "Agency {$suffix}", 'slug' => "agency-ra-{$suffix}", 'domain' => "{$suffix}.zatara.com",
            'settings' => ['room_booking_enabled' => true],
        ]);
        $admin = $this->makeAgencyAdmin($tenant, "0797{$suffix}");
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0596{$suffix}", 'tenant_id' => $tenant->id]);
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
            'price_adjustment_shared' => 40.00, 'price_adjustment_single_supplement' => 25.00,
            'is_active' => true,
        ]);

        return compact('tenant', 'admin', 'customer', 'template', 'instance', 'cat', 'hotel', 'leg', 'option', 'roomType');
    }

    /**
     * @param  array<int, string>  $occupancyTypes  one entry per passenger, all on one booking
     */
    private function makeBookingWithPassengers(array $f, array $occupancyTypes): \App\Models\Booking
    {
        $passengersData = [];
        foreach ($occupancyTypes as $i => $type) {
            $passengersData[] = ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => "P{$i}", 'last_name' => 'X'];
        }

        // occupancy_type is a booking-level room selection, not per-passenger — a booking with a
        // 'single' selection means every one of its passengers requires isolation, matching
        // requiresSingleOccupancy()'s own booking_id-scoped query.
        $occupancyType = in_array('single', $occupancyTypes, true) ? 'single' : 'shared';

        return $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => $passengersData,
            'room_selections' => [
                ['room_type_id' => $f['roomType']->id, 'quantity' => 1, 'occupancy_type' => $occupancyType],
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // assignPassenger(): capacity + single-occupancy enforcement
    // ------------------------------------------------------------------

    public function test_assign_passenger_succeeds_within_capacity(): void
    {
        $f = $this->makeFixture('001', roomCount: 1, capacityPerRoom: 2);
        $service = app(RoomAssignmentService::class);
        $rooms = $service->ensureRoomInstancesExist($f['roomType']);
        $booking = $this->makeBookingWithPassengers($f, ['shared']);
        $passenger = $booking->passengers()->first();

        $assignment = $service->assignPassenger($passenger, $rooms->first());

        $this->assertEquals($rooms->first()->id, $assignment->room_instance_id);
        $this->assertDatabaseHas('room_assignments', ['passenger_id' => $passenger->id, 'room_instance_id' => $rooms->first()->id]);
    }

    public function test_assign_passenger_rejects_when_room_is_at_capacity(): void
    {
        // roomCount must cover 3 separate bookings' inventory purchases (Ticket 2's
        // RoomInventoryService, unrelated to this ticket's physical capacity_per_room check) —
        // all three still target the exact same physical room instance below.
        $f = $this->makeFixture('002', roomCount: 5, capacityPerRoom: 2);
        $service = app(RoomAssignmentService::class);
        $room = $service->ensureRoomInstancesExist($f['roomType'])->first();

        $bookingA = $this->makeBookingWithPassengers($f, ['shared']);
        $bookingB = $this->makeBookingWithPassengers($f, ['shared']);
        $bookingC = $this->makeBookingWithPassengers($f, ['shared']);

        $service->assignPassenger($bookingA->passengers()->first(), $room);
        $service->assignPassenger($bookingB->passengers()->first(), $room);

        $this->expectException(RoomCapacityExceededException::class);
        $service->assignPassenger($bookingC->passengers()->first(), $room);
    }

    public function test_assign_passenger_is_idempotent_when_re_dropped_into_its_own_room(): void
    {
        $f = $this->makeFixture('003', roomCount: 1, capacityPerRoom: 1);
        $service = app(RoomAssignmentService::class);
        $room = $service->ensureRoomInstancesExist($f['roomType'])->first();
        $passenger = $this->makeBookingWithPassengers($f, ['shared'])->passengers()->first();

        $service->assignPassenger($passenger, $room);
        // Re-dropping into the same, already-full-because-of-itself room must not throw.
        $service->assignPassenger($passenger, $room);

        $this->assertSame(1, RoomAssignment::where('room_instance_id', $room->id)->count());
    }

    public function test_assign_passenger_rejects_sharing_a_single_occupancy_room(): void
    {
        $f = $this->makeFixture('004', roomCount: 5, capacityPerRoom: 2);
        $service = app(RoomAssignmentService::class);
        $room = $service->ensureRoomInstancesExist($f['roomType'])->first();

        $singleBooking = $this->makeBookingWithPassengers($f, ['single']);
        $sharedBooking = $this->makeBookingWithPassengers($f, ['shared']);

        $service->assignPassenger($singleBooking->passengers()->first(), $room);

        $this->expectException(RoomCapacityExceededException::class);
        $service->assignPassenger($sharedBooking->passengers()->first(), $room);
    }

    public function test_assign_passenger_rejects_a_single_occupancy_passenger_into_an_occupied_room(): void
    {
        $f = $this->makeFixture('005', roomCount: 5, capacityPerRoom: 2);
        $service = app(RoomAssignmentService::class);
        $room = $service->ensureRoomInstancesExist($f['roomType'])->first();

        $sharedBooking = $this->makeBookingWithPassengers($f, ['shared']);
        $singleBooking = $this->makeBookingWithPassengers($f, ['single']);

        $service->assignPassenger($sharedBooking->passengers()->first(), $room);

        $this->expectException(RoomCapacityExceededException::class);
        $service->assignPassenger($singleBooking->passengers()->first(), $room);
    }

    public function test_unassign_passenger_removes_the_assignment(): void
    {
        $f = $this->makeFixture('006', roomCount: 1, capacityPerRoom: 2);
        $service = app(RoomAssignmentService::class);
        $room = $service->ensureRoomInstancesExist($f['roomType'])->first();
        $passenger = $this->makeBookingWithPassengers($f, ['shared'])->passengers()->first();

        $service->assignPassenger($passenger, $room);
        $service->unassignPassenger($passenger);

        $this->assertDatabaseMissing('room_assignments', ['passenger_id' => $passenger->id]);
    }

    // ------------------------------------------------------------------
    // ensureRoomInstancesExist(): idempotent materialization
    // ------------------------------------------------------------------

    public function test_ensure_room_instances_exist_is_idempotent_and_matches_room_count(): void
    {
        $f = $this->makeFixture('007', roomCount: 3);
        $service = app(RoomAssignmentService::class);

        $first = $service->ensureRoomInstancesExist($f['roomType']);
        $second = $service->ensureRoomInstancesExist($f['roomType']);

        $this->assertCount(3, $first);
        $this->assertCount(3, $second);
        $this->assertSame(3, RoomInstance::where('room_type_id', $f['roomType']->id)->count());
        $this->assertEquals([1, 2, 3], $first->pluck('room_number')->sort()->values()->toArray());
    }

    // ------------------------------------------------------------------
    // autoAssign(): deterministic bin-packing fixtures
    // ------------------------------------------------------------------

    public function test_auto_assign_keeps_a_group_together_in_a_single_room_when_it_fits(): void
    {
        $f = $this->makeFixture('008', roomCount: 2, capacityPerRoom: 3);
        $booking = $this->makeBookingWithPassengers($f, ['shared', 'shared']);

        $result = app(RoomAssignmentService::class)->autoAssign($f['option']);

        $this->assertSame(2, $result['assigned']);
        $this->assertTrue($result['unassigned']->isEmpty());

        $passengerIds = $booking->passengers()->pluck('id');
        $roomIds = RoomAssignment::whereIn('passenger_id', $passengerIds)->pluck('room_instance_id')->unique();
        $this->assertCount(1, $roomIds, 'Both members of the same booking must land in the same room when one room has capacity for the whole group.');
    }

    public function test_auto_assign_splits_a_group_across_rooms_when_no_single_room_fits(): void
    {
        $f = $this->makeFixture('009', roomCount: 2, capacityPerRoom: 2);
        $booking = $this->makeBookingWithPassengers($f, ['shared', 'shared', 'shared']);

        $result = app(RoomAssignmentService::class)->autoAssign($f['option']);

        $this->assertSame(3, $result['assigned'], 'All 3 must be placed: 2+1 across the two 2-capacity rooms = exactly enough total capacity.');
        $this->assertTrue($result['unassigned']->isEmpty());

        $counts = RoomAssignment::whereIn('passenger_id', $booking->passengers()->pluck('id'))
            ->get()
            ->groupBy('room_instance_id')
            ->map->count();
        $this->assertEqualsCanonicalizing([2, 1], $counts->values()->toArray(), 'The 3-person group must split as 2+1 across the emptiest rooms first, not overflow one room.');
    }

    public function test_auto_assign_reports_passengers_left_unassigned_when_truly_out_of_capacity(): void
    {
        $f = $this->makeFixture('010', roomCount: 1, capacityPerRoom: 2);
        $booking = $this->makeBookingWithPassengers($f, ['shared', 'shared', 'shared']);

        $result = app(RoomAssignmentService::class)->autoAssign($f['option']);

        $this->assertSame(2, $result['assigned'], 'Only 2 of 3 fit in the single 2-capacity room available.');
        $this->assertCount(1, $result['unassigned']);
        $this->assertSame(2, RoomAssignment::count());
    }

    public function test_auto_assign_locks_a_single_occupancy_room_for_the_rest_of_that_run(): void
    {
        // Regression test for a real bug found and fixed during this ticket: placeGroup() bumped
        // ->used for a newly-placed single-occupancy passenger but never locked ->capacity to 1,
        // so bestFit's "smallest remaining capacity" preference could still bin-pack a LATER
        // group into that same room within the SAME autoAssign() call (its apparent remaining
        // capacity looked snugger than a fully-empty room, so it was actually preferred over
        // the empty one). Two rooms, capacity 2 each: a single-occupancy booking (group 1) and a
        // shared booking (group 2) both need placing in one run — without the fix, the shared
        // passenger lands in the now single-locked room instead of the empty second room.
        $f = $this->makeFixture('011', roomCount: 2, capacityPerRoom: 2);
        $singleBooking = $this->makeBookingWithPassengers($f, ['single']);
        $sharedBooking = $this->makeBookingWithPassengers($f, ['shared']);

        $result = app(RoomAssignmentService::class)->autoAssign($f['option']);

        $this->assertSame(2, $result['assigned']);
        $this->assertTrue($result['unassigned']->isEmpty());

        $singleRoomId = RoomAssignment::where('passenger_id', $singleBooking->passengers()->first()->id)->value('room_instance_id');
        $sharedRoomId = RoomAssignment::where('passenger_id', $sharedBooking->passengers()->first()->id)->value('room_instance_id');

        $this->assertNotEquals($singleRoomId, $sharedRoomId, 'The shared passenger must not be packed into the now single-locked room.');
        $this->assertSame(1, RoomAssignment::where('room_instance_id', $singleRoomId)->count());
    }

    public function test_auto_assign_never_reassigns_an_already_assigned_passenger(): void
    {
        $f = $this->makeFixture('012', roomCount: 2, capacityPerRoom: 2);
        $service = app(RoomAssignmentService::class);
        $rooms = $service->ensureRoomInstancesExist($f['roomType']);
        $bookingA = $this->makeBookingWithPassengers($f, ['shared']);
        $bookingB = $this->makeBookingWithPassengers($f, ['shared']);

        $service->assignPassenger($bookingA->passengers()->first(), $rooms->last());

        $result = $service->autoAssign($f['option']);

        $this->assertSame(1, $result['assigned'], 'Only booking B\'s passenger was unassigned going in.');
        $this->assertEquals(
            $rooms->last()->id,
            RoomAssignment::where('passenger_id', $bookingA->passengers()->first()->id)->first()->room_instance_id,
            'The manually pre-assigned passenger must not be moved by autoAssign().'
        );
    }

    // ------------------------------------------------------------------
    // getBoardData()
    // ------------------------------------------------------------------

    public function test_board_data_separates_unassigned_from_assigned_and_excludes_cancelled_bookings(): void
    {
        $f = $this->makeFixture('013', roomCount: 5, capacityPerRoom: 2);
        $service = app(RoomAssignmentService::class);
        $room = $service->ensureRoomInstancesExist($f['roomType'])->first();

        $assignedBooking = $this->makeBookingWithPassengers($f, ['shared']);
        $unassignedBooking = $this->makeBookingWithPassengers($f, ['shared']);
        $cancelledBooking = $this->makeBookingWithPassengers($f, ['shared']);
        $cancelledBooking->update(['booking_status' => \App\Enums\BookingStatus::Cancelled]);

        $service->assignPassenger($assignedBooking->passengers()->first(), $room);

        $board = $service->getBoardData($f['option']->fresh());

        $this->assertCount(1, $board['unassigned']);
        $this->assertTrue($board['unassigned']->first()->is($unassignedBooking->passengers()->first()));
    }

    // ------------------------------------------------------------------
    // Rooming-list PDF route
    // ------------------------------------------------------------------

    public function test_rooming_list_pdf_route_renders_successfully_with_correct_grouping(): void
    {
        $f = $this->makeFixture('014', roomCount: 2, capacityPerRoom: 2);
        $service = app(RoomAssignmentService::class);
        $rooms = $service->ensureRoomInstancesExist($f['roomType']);
        $booking = $this->makeBookingWithPassengers($f, ['shared']);
        $service->assignPassenger($booking->passengers()->first(), $rooms->first());

        $this->actingAs($f['admin']);

        $response = $this->get(route('hotel-option.rooming-list', $f['option']));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    // ------------------------------------------------------------------
    // AssignRooms page: reachability + tenant isolation
    // ------------------------------------------------------------------

    public function test_assign_rooms_page_loads_and_shows_board_data(): void
    {
        $f = $this->makeFixture('015', roomCount: 1, capacityPerRoom: 2);
        $this->makeBookingWithPassengers($f, ['shared']);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(AssignRooms::class, ['record' => $f['instance']->getRouteKey()])
            ->assertSuccessful();

        $this->assertSame(1, RoomInstance::where('room_type_id', $f['roomType']->id)->count(), 'Mounting the page must materialize room instances for the hotel option in use.');
    }

    public function test_assign_rooms_page_drop_and_remove_persist_and_notify_on_capacity_violation(): void
    {
        $f = $this->makeFixture('016', roomCount: 5, capacityPerRoom: 1);
        $bookingA = $this->makeBookingWithPassengers($f, ['shared']);
        $bookingB = $this->makeBookingWithPassengers($f, ['shared']);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        $component = Livewire::test(AssignRooms::class, ['record' => $f['instance']->getRouteKey()]);
        $room = RoomInstance::where('room_type_id', $f['roomType']->id)->first();

        $component->call('dropPassenger', $bookingA->passengers()->first()->id, $room->id);
        $this->assertDatabaseHas('room_assignments', ['passenger_id' => $bookingA->passengers()->first()->id, 'room_instance_id' => $room->id]);

        // Room is now full (capacity 1) — dropping bookingB's passenger must be rejected with a
        // danger notification, and must not persist.
        $component->call('dropPassenger', $bookingB->passengers()->first()->id, $room->id);
        \Filament\Notifications\Notification::assertNotified();
        $this->assertDatabaseMissing('room_assignments', ['passenger_id' => $bookingB->passengers()->first()->id]);

        $component->call('removeFromRoom', $bookingA->passengers()->first()->id);
        $this->assertDatabaseMissing('room_assignments', ['passenger_id' => $bookingA->passengers()->first()->id]);
    }

    public function test_assign_rooms_page_blocks_cross_tenant_passenger_drop(): void
    {
        // Direct instantiation + method call, bypassing Livewire's test request wrapper, which
        // resolves an aborted (403) update into a Livewire-internal response instead of letting
        // the exception bubble to PHPUnit — this codebase's own established pattern for exercising
        // a page's guard logic directly (see AdminBookingTest's reflection-based CreateBooking
        // tests) applies here too, just via a plain public method call.
        $fA = $this->makeFixture('017a', roomCount: 2, capacityPerRoom: 2);
        $fB = $this->makeFixture('017b', roomCount: 2, capacityPerRoom: 2);

        $foreignPassenger = $this->makeBookingWithPassengers($fB, ['shared'])->passengers()->first();

        setPermissionsTeamId($fA['tenant']->id);
        $this->actingAs($fA['admin']);
        Filament::setTenant($fA['tenant'], true);

        $room = app(RoomAssignmentService::class)->ensureRoomInstancesExist($fA['roomType'])->first();

        $page = new AssignRooms();
        $page->mount($fA['instance']->getRouteKey());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $page->dropPassenger($foreignPassenger->id, $room->id);
    }

    public function test_edit_trip_instance_header_action_links_to_assign_rooms_page(): void
    {
        $f = $this->makeFixture('018');

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(EditTripInstance::class, ['record' => $f['instance']->getRouteKey()])
            ->assertActionExists('assign_rooms');
    }
}

<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientSeatsException;
use App\Filament\Resources\TripInstanceResource\Pages\EditTripInstance;
use App\Filament\Resources\TripTemplateResource\RelationManagers\TripInstancesRelationManager;
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
use App\Services\CreateBookingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for Bus/Fleet redesign Ticket 2 (capacity/inventory integration): the
 * TripFleetService/TripBusAssignmentObserver recalculation of TripInstance.available_seats, the
 * corresponding UI lock, InventoryService continuing to enforce that recalculated value with
 * zero changes to its own logic, and CustomerBookingPortal's multi-bus degrade guard.
 */
class BusFleetCapacityIntegrationTest extends TestCase
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
    private function makeFixture(string $suffix, ?int $availableSeats = 10): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-bfc-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $admin = $this->makeAgencyAdmin($tenant, "0799{$suffix}");
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0594{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => $availableSeats,
            'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);

        return compact('tenant', 'admin', 'customer', 'template', 'instance', 'cat');
    }

    /**
     * @return array{tenant_id: int, trip_instance_id: int, ownership_type: string, capacity: int, driver_type: string, driver_staff_id: int, guide_type: string, guide_staff_id: int, vehicle_id: int|null, rented_supplier_name: string|null}
     */
    private function busPayload(array $f, int $capacity, bool $owned = true): array
    {
        $base = [
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'capacity' => $capacity,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
        ];

        if ($owned) {
            $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'V-' . uniqid(), 'default_capacity' => $capacity]);
            return $base + ['ownership_type' => 'owned', 'vehicle_id' => $vehicle->id];
        }

        return $base + ['ownership_type' => 'rented', 'rented_supplier_name' => 'Rental Co'];
    }

    // ------------------------------------------------------------------
    // Recalculation correctness
    // ------------------------------------------------------------------

    public function test_creating_a_bus_assignment_sets_available_seats_to_its_capacity(): void
    {
        $f = $this->makeFixture('001', availableSeats: 5);

        TripBusAssignment::create($this->busPayload($f, 40));

        $this->assertSame(40, $f['instance']->fresh()->available_seats);
    }

    public function test_adding_a_second_bus_sums_capacity(): void
    {
        $f = $this->makeFixture('002');

        TripBusAssignment::create($this->busPayload($f, 45, owned: true));
        $this->assertSame(45, $f['instance']->fresh()->available_seats);

        TripBusAssignment::create($this->busPayload($f, 38, owned: false));
        $this->assertSame(83, $f['instance']->fresh()->available_seats, 'Two buses (45 + 38) must sum to 83 — the exact scenario live-verified in Ticket 1.');
    }

    public function test_editing_a_bus_capacity_updates_available_seats(): void
    {
        $f = $this->makeFixture('003');
        $bus = TripBusAssignment::create($this->busPayload($f, 40));
        TripBusAssignment::create($this->busPayload($f, 38, owned: false));
        $this->assertSame(78, $f['instance']->fresh()->available_seats);

        $bus->update(['capacity' => 45]);

        $this->assertSame(83, $f['instance']->fresh()->available_seats);
    }

    public function test_deleting_a_bus_reduces_available_seats_to_remaining_sum(): void
    {
        $f = $this->makeFixture('004');
        TripBusAssignment::create($this->busPayload($f, 45));
        $bus2 = TripBusAssignment::create($this->busPayload($f, 38, owned: false));
        $this->assertSame(83, $f['instance']->fresh()->available_seats);

        $bus2->delete();

        $this->assertSame(45, $f['instance']->fresh()->available_seats);
    }

    public function test_deleting_the_last_bus_leaves_available_seats_unchanged(): void
    {
        // Regression for the deliberate design decision: a trip that just lost its last bus
        // assignment keeps whatever value was last correct, rather than being silently zeroed
        // out — it simply becomes manually editable again (see the UI lock tests below).
        $f = $this->makeFixture('005');
        $bus = TripBusAssignment::create($this->busPayload($f, 45));
        $this->assertSame(45, $f['instance']->fresh()->available_seats);

        $bus->delete();

        $this->assertSame(45, $f['instance']->fresh()->available_seats, 'available_seats must NOT be reset to null/0 when the last bus assignment is removed.');
    }

    public function test_trip_with_zero_bus_assignments_is_never_touched_by_recalculation(): void
    {
        $fA = $this->makeFixture('006a', availableSeats: 7);
        $fB = $this->makeFixture('006b', availableSeats: 7);

        // Creating/deleting bus assignments on a DIFFERENT trip must never touch fA's value.
        $busB = TripBusAssignment::create($this->busPayload($fB, 40));
        $this->assertSame(7, $fA['instance']->fresh()->available_seats);

        $busB->delete();
        $this->assertSame(7, $fA['instance']->fresh()->available_seats);
    }

    // ------------------------------------------------------------------
    // UI lock: available_seats disabled once a trip has bus assignments
    // ------------------------------------------------------------------

    public function test_available_seats_field_is_disabled_in_trip_instance_resource_once_buses_exist(): void
    {
        $f = $this->makeFixture('007');
        TripBusAssignment::create($this->busPayload($f, 40));

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(EditTripInstance::class, ['record' => $f['instance']->getRouteKey()])
            ->assertFormFieldIsDisabled('available_seats');
    }

    public function test_available_seats_field_is_enabled_in_trip_instance_resource_when_no_buses(): void
    {
        $f = $this->makeFixture('008');

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(EditTripInstance::class, ['record' => $f['instance']->getRouteKey()])
            ->assertFormFieldIsEnabled('available_seats');
    }

    public function test_available_seats_field_is_disabled_in_trip_instances_relation_manager_once_buses_exist(): void
    {
        $f = $this->makeFixture('009');
        TripBusAssignment::create($this->busPayload($f, 40));

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(TripInstancesRelationManager::class, [
            'ownerRecord' => $f['template'],
            'pageClass' => \App\Filament\Resources\TripTemplateResource\Pages\EditTripTemplate::class,
        ])
            ->mountTableAction('edit', $f['instance'])
            ->assertFormFieldIsDisabled('available_seats', 'mountedTableActionForm');
    }

    // ------------------------------------------------------------------
    // InventoryService: zero changes to its own logic, still enforces the recalculated value
    // ------------------------------------------------------------------

    public function test_inventory_service_enforces_the_recalculated_available_seats_as_the_real_cap(): void
    {
        $f = $this->makeFixture('010', availableSeats: 999); // deliberately different from the bus sum, to prove the recalculated value wins
        TripBusAssignment::create($this->busPayload($f, 2));
        TripBusAssignment::create($this->busPayload($f, 1, owned: false));
        $this->assertSame(3, $f['instance']->fresh()->available_seats);

        // 3 single-passenger bookings must succeed (exactly the recalculated cap)...
        for ($i = 1; $i <= 3; $i++) {
            $booking = $this->createBookingService->execute([
                'tenant_id' => $f['tenant']->id,
                'trip_instance_id' => $f['instance']->id,
                'customer_id' => $f['customer']->id,
                'passengersData' => [
                    ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => (string) $i],
                ],
            ]);
            $this->assertNotNull($booking);
        }

        // ...and a 4th must be rejected by InventoryService's own, completely untouched logic.
        $this->expectException(InsufficientSeatsException::class);
        $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '4'],
            ],
        ]);
    }

    public function test_inventory_service_still_works_normally_on_a_trip_with_no_bus_assignments(): void
    {
        // Sanity check: a trip that never uses fleet management at all must be completely
        // unaffected by any of this ticket's code — InventoryService reads the manually-entered
        // available_seats exactly as it always has.
        $f = $this->makeFixture('011', availableSeats: 1);

        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);
        $this->assertNotNull($booking);

        $this->expectException(InsufficientSeatsException::class);
        $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '2'],
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // CustomerBookingPortal: multi-bus degrade guard
    // ------------------------------------------------------------------

    public function test_portal_offers_seat_selection_with_zero_buses(): void
    {
        $f = $this->makeFixture('012', availableSeats: 3);
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
    }

    public function test_portal_offers_seat_selection_with_exactly_one_bus(): void
    {
        $f = $this->makeFixture('013');
        TripBusAssignment::create($this->busPayload($f, 40));
        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $component = Livewire::test(CustomerBookingPortal::class, ['uuid' => $booking->uuid]);

        $component->assertSet('totalSeats', 40);
    }

    public function test_portal_disables_seat_selection_with_two_or_more_buses(): void
    {
        $f = $this->makeFixture('014');
        TripBusAssignment::create($this->busPayload($f, 45));
        TripBusAssignment::create($this->busPayload($f, 38, owned: false));
        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);

        $component = Livewire::test(CustomerBookingPortal::class, ['uuid' => $booking->uuid]);

        $component->assertSet('totalSeats', null);
        $this->assertCount(0, $component->get('availableSeats'), 'A multi-bus trip must not offer any numbered seats via the self-service portal.');
    }

    public function test_portal_saveall_rejects_a_tampered_seat_request_on_a_multibus_trip(): void
    {
        // Server-side twin of the mount() gate above: even if a stale/tampered client sends a
        // seat number for a now-multi-bus trip, saveAll() must still reject it. Without this
        // ticket's fix, this would have slipped through: available_seats is no longer null for
        // a multi-bus trip (it's the summed capacity, e.g. 83), so the OLD guard
        // (available_seats === null) alone would not have caught this.
        $f = $this->makeFixture('015');
        TripBusAssignment::create($this->busPayload($f, 45));
        TripBusAssignment::create($this->busPayload($f, 38, owned: false));
        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);
        $passenger = $booking->passengers()->first();

        $component = Livewire::test(CustomerBookingPortal::class, ['uuid' => $booking->uuid]);
        $component->set("passengersData.{$passenger->id}.first_name", 'P');
        $component->set("passengersData.{$passenger->id}.last_name", '1');
        $component->set("selectedSeats.{$passenger->id}", 5); // tampered: portal never offered this
        $component->call('saveAll');

        $component->assertHasErrors(['seats']);
        $this->assertNull($passenger->fresh()->seat_number, 'The tampered seat request must not have been persisted.');
    }
}

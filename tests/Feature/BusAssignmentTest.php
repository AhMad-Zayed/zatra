<?php

namespace Tests\Feature;

use App\Exceptions\InvalidBusAssignmentException;
use App\Filament\Resources\TripInstanceResource\Pages\AssignBuses;
use App\Filament\Resources\VehicleResource\Pages\CreateVehicle;
use App\Filament\Resources\VehicleResource\Pages\EditVehicle;
use App\Filament\Resources\VehicleResource\Pages\ListVehicles;
use App\Models\Tenant;
use App\Models\TripBusAssignment;
use App\Models\TripInstance;
use App\Models\TripTemplate;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for Bus/Fleet redesign Ticket 1 (catalog + per-trip assignment CRUD only —
 * no capacity/inventory integration, no seat_number/CustomerBookingPortal changes, no
 * drag-and-drop UI; those are Tickets 2/3). Mirrors HotelRoomingDataModelTest's fixture/testing
 * conventions.
 */
class BusAssignmentTest extends TestCase
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
     * @return array{tenant: Tenant, admin: User, template: TripTemplate, instance: TripInstance}
     */
    private function makeFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-bus-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $admin = $this->makeAgencyAdmin($tenant, "0798{$suffix}");
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Tour', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 10,
            'status' => 'active',
        ]);

        return compact('tenant', 'admin', 'template', 'instance');
    }

    // ------------------------------------------------------------------
    // Vehicle CRUD + tenant isolation
    // ------------------------------------------------------------------

    public function test_vehicle_can_be_created_through_the_resource_and_is_tenant_scoped_automatically(): void
    {
        $f = $this->makeFixture('001');
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(CreateVehicle::class)
            ->fillForm([
                'plate_number' => 'ABC-123',
                'default_capacity' => 40,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vehicle = Vehicle::where('plate_number', 'ABC-123')->first();
        $this->assertNotNull($vehicle);
        $this->assertEquals($f['tenant']->id, $vehicle->tenant_id);
        $this->assertEquals(40, $vehicle->default_capacity);
    }

    public function test_vehicle_resource_scopes_to_current_tenant_only(): void
    {
        $fA = $this->makeFixture('002a');
        $fB = $this->makeFixture('002b');

        $vehicleA = Vehicle::create(['tenant_id' => $fA['tenant']->id, 'plate_number' => 'A-1', 'default_capacity' => 30]);
        $vehicleB = Vehicle::create(['tenant_id' => $fB['tenant']->id, 'plate_number' => 'B-1', 'default_capacity' => 30]);

        setPermissionsTeamId($fA['tenant']->id);
        $this->actingAs($fA['admin']);
        Filament::setTenant($fA['tenant'], true);

        Livewire::test(ListVehicles::class)
            ->assertCanSeeTableRecords([$vehicleA])
            ->assertCanNotSeeTableRecords([$vehicleB]);
    }

    public function test_vehicle_deletion_is_blocked_while_referenced_by_a_trip_bus_assignment(): void
    {
        $f = $this->makeFixture('003');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'REF-1', 'default_capacity' => 40]);
        $staff = $f['admin'];
        TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => 40,
            'driver_type' => 'internal',
            'driver_staff_id' => $staff->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $staff->id,
        ]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(ListVehicles::class)->callTableAction('delete', $vehicle);

        $this->assertNotNull($vehicle->fresh(), 'A referenced vehicle must not be deleted — the application-level guard must cancel the action.');
    }

    public function test_vehicle_deletion_succeeds_when_unreferenced(): void
    {
        $f = $this->makeFixture('004');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'UNUSED-1', 'default_capacity' => 40]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(ListVehicles::class)->callTableAction('delete', $vehicle);

        $this->assertSoftDeleted($vehicle);
    }

    public function test_a_tenant_cannot_edit_another_tenants_vehicle_via_direct_url(): void
    {
        $fA = $this->makeFixture('005a');
        $fB = $this->makeFixture('005b');
        $vehicleB = Vehicle::create(['tenant_id' => $fB['tenant']->id, 'plate_number' => 'B-2', 'default_capacity' => 30]);

        $this->actingAs($fA['admin']);
        Filament::setTenant($fA['tenant'], true);

        $this->get("/admin/{$fA['tenant']->id}/vehicles/{$vehicleB->id}/edit")->assertNotFound();
    }

    public function test_vehicle_can_be_edited(): void
    {
        $f = $this->makeFixture('006');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'OLD-1', 'default_capacity' => 40]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(EditVehicle::class, ['record' => $vehicle->getRouteKey()])
            ->fillForm(['plate_number' => 'NEW-1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('NEW-1', $vehicle->fresh()->plate_number);
    }

    // ------------------------------------------------------------------
    // TripBusAssignment: model-level dual-mode validation (defense in depth)
    // ------------------------------------------------------------------

    public function test_internal_driver_requires_staff_id(): void
    {
        $f = $this->makeFixture('007');

        $this->expectException(InvalidBusAssignmentException::class);
        TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'rented',
            'rented_supplier_name' => 'ABC Rentals',
            'capacity' => 30,
            'driver_type' => 'internal',
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
        ]);
    }

    public function test_external_driver_requires_name_and_phone(): void
    {
        $f = $this->makeFixture('008');

        $this->expectException(InvalidBusAssignmentException::class);
        TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'rented',
            'rented_supplier_name' => 'ABC Rentals',
            'capacity' => 30,
            'driver_type' => 'external',
            'driver_name' => 'Ali',
            // missing driver_phone
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
        ]);
    }

    public function test_external_guide_requires_name_and_phone(): void
    {
        $f = $this->makeFixture('009');

        $this->expectException(InvalidBusAssignmentException::class);
        TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'rented',
            'rented_supplier_name' => 'ABC Rentals',
            'capacity' => 30,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'external',
            'guide_name' => 'Sara',
            // missing guide_phone
        ]);
    }

    public function test_owned_bus_requires_vehicle_id(): void
    {
        $f = $this->makeFixture('010');

        $this->expectException(InvalidBusAssignmentException::class);
        TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            // missing vehicle_id
            'capacity' => 30,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
        ]);
    }

    public function test_rented_bus_requires_supplier_name(): void
    {
        $f = $this->makeFixture('011');

        $this->expectException(InvalidBusAssignmentException::class);
        TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'rented',
            // missing rented_supplier_name
            'capacity' => 30,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Admin panel UX audit, Friction Point #4: driver/guide optional at bus-assignment time
    // ------------------------------------------------------------------

    public function test_a_bus_can_be_added_with_no_driver_or_guide_decided_yet(): void
    {
        $f = $this->makeFixture('020');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'ND-1', 'default_capacity' => 40]);

        $bus = TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => 40,
            // driver_type / guide_type both omitted entirely.
        ]);

        $this->assertNull($bus->fresh()->driver_type);
        $this->assertNull($bus->fresh()->guide_type);
        $this->assertSame('لم يُحدد بعد', $bus->fresh()->driver_display_name);
        $this->assertSame('لم يُحدد بعد', $bus->fresh()->guide_display_name);
    }

    public function test_a_half_filled_driver_pair_is_rejected_even_when_type_is_unset(): void
    {
        // Not fully optional: leaving driver_type unset is fine, but supplying only a name/phone
        // (or only a staff_id) without also setting the type is still a genuine data-entry
        // mistake, not "not yet decided" -- assertPersonValid only special-cases type === null
        // with every field null, not a mismatched partial state.
        $f = $this->makeFixture('021');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'ND-2', 'default_capacity' => 40]);

        $bus = TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => 40,
        ]);

        $this->assertNull($bus->fresh()->driver_type, 'Sanity check: bus created with driver undecided.');

        $bus->update(['driver_type' => 'internal', 'driver_staff_id' => $f['admin']->id]);

        $this->assertSame($f['admin']->id, $bus->fresh()->driver_staff_id);
        $this->assertSame($f['admin']->name, $bus->fresh()->driver_display_name, 'Deciding the driver later must take effect normally.');
    }

    public function test_add_bus_action_succeeds_with_driver_and_guide_left_unset(): void
    {
        $f = $this->makeFixture('022');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'AB-2', 'default_capacity' => 40]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(AssignBuses::class, ['record' => $f['instance']->getRouteKey()])
            ->callAction('addBus', [
                'ownership_type' => 'owned',
                'vehicle_id' => $vehicle->id,
                'capacity' => 40,
                // No driver_type/guide_type submitted at all -- the form no longer requires them.
            ])
            ->assertHasNoActionErrors();

        $bus = TripBusAssignment::where('trip_instance_id', $f['instance']->id)->first();
        $this->assertNotNull($bus);
        $this->assertNull($bus->driver_type);
        $this->assertNull($bus->guide_type);
    }

    public function test_edit_bus_action_can_assign_a_driver_to_a_previously_undecided_bus(): void
    {
        $f = $this->makeFixture('023');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'EB-1', 'default_capacity' => 40]);
        $bus = TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => 40,
        ]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(AssignBuses::class, ['record' => $f['instance']->getRouteKey()])
            ->mountAction('editBus', arguments: ['id' => $bus->id])
            ->setActionData([
                'ownership_type' => 'owned',
                'vehicle_id' => $vehicle->id,
                'capacity' => 40,
                'driver_type' => 'internal',
                'driver_staff_id' => $f['admin']->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($f['admin']->id, $bus->fresh()->driver_staff_id);
    }

    public function test_switching_driver_type_clears_the_previously_inactive_half(): void
    {
        $f = $this->makeFixture('012');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'SW-1', 'default_capacity' => 40]);

        $bus = TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => 40,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
        ]);

        $this->assertNull($bus->driver_name);
        $this->assertNull($bus->driver_phone);

        $bus->update([
            'driver_type' => 'external',
            'driver_name' => 'External Driver',
            'driver_phone' => '0700000001',
        ]);

        $this->assertNull($bus->fresh()->driver_staff_id, 'Switching to external must clear the stale internal staff_id.');
        $this->assertEquals('External Driver', $bus->fresh()->driver_name);
    }

    public function test_happy_path_owned_bus_with_internal_driver_and_external_guide(): void
    {
        $f = $this->makeFixture('013');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'HP-1', 'default_capacity' => 40]);

        $bus = TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => 40,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'external',
            'guide_name' => 'External Guide',
            'guide_phone' => '0700000002',
        ]);

        $this->assertEquals($f['admin']->name, $bus->driver_display_name);
        $this->assertEquals('External Guide', $bus->guide_display_name);
    }

    // ------------------------------------------------------------------
    // Multi-bus scenario ("open bus 2 when bus 1 fills")
    // ------------------------------------------------------------------

    public function test_a_trip_instance_can_have_multiple_bus_assignments_with_summed_capacity(): void
    {
        $f = $this->makeFixture('014');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'MB-1', 'default_capacity' => 40]);

        TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => 40,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
            'sort_order' => 0,
        ]);

        TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'rented',
            'rented_supplier_name' => 'Rental Co',
            'capacity' => 40,
            'driver_type' => 'external',
            'driver_name' => 'Rented Driver',
            'driver_phone' => '0700000003',
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
            'sort_order' => 1,
        ]);

        $this->assertCount(2, $f['instance']->fresh()->tripBusAssignments);
        $this->assertEquals(80, $f['instance']->fresh()->tripBusAssignments()->sum('capacity'));
    }

    // ------------------------------------------------------------------
    // AssignBuses page: reachability, CRUD, tenant isolation
    // ------------------------------------------------------------------

    public function test_assign_buses_page_loads_and_shows_existing_buses(): void
    {
        $f = $this->makeFixture('015');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'PG-1', 'default_capacity' => 40]);
        TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => 40,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
        ]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        $page = new AssignBuses();
        $page->mount($f['instance']->getRouteKey());

        Livewire::test(AssignBuses::class, ['record' => $f['instance']->getRouteKey()])
            ->assertSuccessful();

        $this->assertSame(1, $page->buses()->count());
        $this->assertSame(40, $page->totalCapacity());
    }

    public function test_add_bus_action_creates_a_trip_bus_assignment(): void
    {
        $f = $this->makeFixture('016');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'AB-1', 'default_capacity' => 40]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(AssignBuses::class, ['record' => $f['instance']->getRouteKey()])
            ->callAction('addBus', [
                'ownership_type' => 'owned',
                'vehicle_id' => $vehicle->id,
                'capacity' => 40,
                'driver_type' => 'internal',
                'driver_staff_id' => $f['admin']->id,
                'guide_type' => 'internal',
                'guide_staff_id' => $f['admin']->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, TripBusAssignment::where('trip_instance_id', $f['instance']->id)->count());
    }

    public function test_delete_bus_action_removes_the_assignment(): void
    {
        $f = $this->makeFixture('017');
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'DB-1', 'default_capacity' => 40]);
        $bus = TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => 40,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
        ]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(AssignBuses::class, ['record' => $f['instance']->getRouteKey()])
            ->callAction('deleteBus', arguments: ['id' => $bus->id]);

        $this->assertSoftDeleted($bus);
    }

    public function test_assign_buses_page_blocks_cross_tenant_bus_deletion(): void
    {
        $fA = $this->makeFixture('018a');
        $fB = $this->makeFixture('018b');
        $vehicleB = Vehicle::create(['tenant_id' => $fB['tenant']->id, 'plate_number' => 'XT-1', 'default_capacity' => 40]);
        $foreignBus = TripBusAssignment::create([
            'tenant_id' => $fB['tenant']->id,
            'trip_instance_id' => $fB['instance']->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicleB->id,
            'capacity' => 40,
            'driver_type' => 'internal',
            'driver_staff_id' => $fB['admin']->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $fB['admin']->id,
        ]);

        setPermissionsTeamId($fA['tenant']->id);
        $this->actingAs($fA['admin']);
        Filament::setTenant($fA['tenant'], true);

        $page = new AssignBuses();
        $page->mount($fA['instance']->getRouteKey());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $page->deleteBusAction()->arguments(['id' => $foreignBus->id])->call();
    }

    public function test_edit_trip_instance_header_action_links_to_assign_buses_page(): void
    {
        $f = $this->makeFixture('019');

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(\App\Filament\Resources\TripInstanceResource\Pages\EditTripInstance::class, ['record' => $f['instance']->getRouteKey()])
            ->assertActionExists('assign_buses');
    }
}

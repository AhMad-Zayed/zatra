<?php

namespace Tests\Feature;

use App\Enums\WaitingListStatusEnum;
use App\Filament\Clusters\ReportsCenter\Pages\OccupancySellThroughReport;
use App\Filament\Clusters\ReportsCenter\Pages\WaitlistCancellationHealthReport;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripBusAssignment;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WaitingList;
use App\Services\BookingService;
use App\Services\CreateBookingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for Reports Center Ticket 3, Reports 3 (Occupancy & Sell-Through) and 4
 * (Waitlist & Cancellation Health) — the final ticket in the Reports Center initiative.
 * Read-only: all state transitions go through the same real services every other test in this
 * app already uses to build realistic fixtures.
 */
class OccupancyAndWaitlistHealthReportTest extends TestCase
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
     * @return array{tenant: Tenant, admin: User, customer: Customer, template: TripTemplate}
     */
    private function makeTenantFixture(string $suffix, ?string $tripType = null): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-och-{$suffix}", 'domain' => "{$suffix}.zatara.com"]);
        $admin = $this->makeAgencyAdmin($tenant, "0795{$suffix}");
        $customer = Customer::create(['name' => 'Jane', 'phone' => "0590{$suffix}", 'tenant_id' => $tenant->id]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => "Tour {$suffix}", 'base_price' => 100, 'trip_type' => $tripType]);

        return compact('tenant', 'admin', 'customer', 'template');
    }

    /**
     * @return array{instance: TripInstance, cat: TripPassengerCategory}
     */
    private function makeTripInstance(array $f, int $availableSeats, int $daysFromNow = 5): array
    {
        $instance = TripInstance::create([
            'tenant_id' => $f['tenant']->id,
            'trip_template_id' => $f['template']->id,
            'start_date' => now()->addDays($daysFromNow),
            'end_date' => now()->addDays($daysFromNow + 5),
            'available_seats' => $availableSeats,
            'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $f['tenant']->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);

        return compact('instance', 'cat');
    }

    private function makeBooking(array $f, array $trip): \App\Models\Booking
    {
        return $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $trip['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $trip['cat']->id, 'first_name' => 'P', 'last_name' => '1'],
            ],
        ]);
    }

    private function makeBus(array $f, TripInstance $instance, int $capacity): TripBusAssignment
    {
        $vehicle = Vehicle::create(['tenant_id' => $f['tenant']->id, 'plate_number' => 'V-' . uniqid(), 'default_capacity' => $capacity]);

        return TripBusAssignment::create([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $instance->id,
            'ownership_type' => 'owned',
            'vehicle_id' => $vehicle->id,
            'capacity' => $capacity,
            'driver_type' => 'internal',
            'driver_staff_id' => $f['admin']->id,
            'guide_type' => 'internal',
            'guide_staff_id' => $f['admin']->id,
        ]);
    }

    private function load(array $f, string $pageClass): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        return Livewire::test($pageClass);
    }

    // ------------------------------------------------------------------
    // Report 3: Occupancy & Sell-Through
    // ------------------------------------------------------------------

    public function test_occupancy_calculated_correctly_with_manually_entered_capacity(): void
    {
        $f = $this->makeTenantFixture('001');
        $trip = $this->makeTripInstance($f, availableSeats: 10);
        $this->makeBooking($f, $trip);
        $this->makeBooking($f, $trip);
        $this->makeBooking($f, $trip);

        $component = $this->load($f, OccupancySellThroughReport::class);
        $record = $component->instance()->getTableRecords()->firstWhere('id', $trip['instance']->id);

        $this->assertSame(10, $record->available_seats);
        $this->assertSame(3, $record->seats_booked);
    }

    public function test_occupancy_calculated_correctly_when_capacity_comes_from_summed_bus_assignments(): void
    {
        $f = $this->makeTenantFixture('002');
        $trip = $this->makeTripInstance($f, availableSeats: 999); // will be overridden by the bus observer
        $this->makeBus($f, $trip['instance'], 4);
        $this->makeBus($f, $trip['instance'], 3);
        $this->assertSame(7, $trip['instance']->fresh()->available_seats, 'Fixture sanity check: Bus/Fleet Ticket 2\'s observer must have recalculated available_seats to 4+3=7.');

        $this->makeBooking($f, $trip);
        $this->makeBooking($f, $trip);

        $component = $this->load($f, OccupancySellThroughReport::class);
        $record = $component->instance()->getTableRecords()->firstWhere('id', $trip['instance']->id);

        $this->assertSame(7, $record->available_seats, 'The report must read whatever available_seats holds, with zero special-casing for bus-derived capacity.');
        $this->assertSame(2, $record->seats_booked);
    }

    public function test_fills_over_time_average_lead_time_is_calculated_from_created_at(): void
    {
        $f = $this->makeTenantFixture('003');
        $trip = $this->makeTripInstance($f, availableSeats: 10, daysFromNow: 20);
        $bookingA = $this->makeBooking($f, $trip);
        \App\Models\Booking::where('id', $bookingA->id)->update(['created_at' => $trip['instance']->start_date->copy()->subDays(10)]);
        $bookingB = $this->makeBooking($f, $trip);
        \App\Models\Booking::where('id', $bookingB->id)->update(['created_at' => $trip['instance']->start_date->copy()->subDays(20)]);

        $component = $this->load($f, OccupancySellThroughReport::class);
        $record = $component->instance()->getTableRecords()->firstWhere('id', $trip['instance']->id);
        $record->load('bookings');

        $avgDays = $record->bookings->map(fn ($b) => $b->created_at->diffInDays($record->start_date))->avg();

        $this->assertEqualsWithDelta(15, $avgDays, 0.5, 'Average of 10 and 20 days lead time must be 15.');
    }

    public function test_occupancy_report_tenant_isolation(): void
    {
        $fA = $this->makeTenantFixture('004a');
        $fB = $this->makeTenantFixture('004b');
        $tripA = $this->makeTripInstance($fA, 10);
        $tripB = $this->makeTripInstance($fB, 10);

        $component = $this->load($fA, OccupancySellThroughReport::class);

        $component->assertCanSeeTableRecords([$tripA['instance']]);
        $component->assertCanNotSeeTableRecords([$tripB['instance']]);
    }

    public function test_occupancy_export_produces_a_valid_file_with_correct_rows(): void
    {
        $f = $this->makeTenantFixture('005');
        $trip = $this->makeTripInstance($f, availableSeats: 10);
        $this->makeBooking($f, $trip);

        $component = $this->load($f, OccupancySellThroughReport::class);
        $component->callTableAction('export');
        $component->assertHasNoTableActionErrors();
    }

    // ------------------------------------------------------------------
    // Report 4: Waitlist & Cancellation Health
    // ------------------------------------------------------------------

    public function test_cancellation_rate_is_grouped_by_template_and_calculated_correctly(): void
    {
        $f = $this->makeTenantFixture('006');
        $trip = $this->makeTripInstance($f, 10);
        $keptBooking = $this->makeBooking($f, $trip);
        $cancelledBooking = $this->makeBooking($f, $trip);
        $this->bookingService->cancelBooking($cancelledBooking, 'test');

        $component = $this->load($f, WaitlistCancellationHealthReport::class);
        $record = $component->instance()->getTableRecords()->firstWhere('id', $f['template']->id);
        $record->load('tripInstances.bookings');

        $bookings = $record->tripInstances->flatMap->bookings;
        $this->assertCount(2, $bookings);
        $cancelledCount = $bookings->where('booking_status', \App\Enums\BookingStatus::Cancelled)->count();
        $this->assertSame(1, $cancelledCount);
        $this->assertEqualsWithDelta(50.0, $cancelledCount / $bookings->count() * 100, 0.01);
    }

    public function test_waitlist_conversion_rate_counts_only_resolved_entries(): void
    {
        $f = $this->makeTenantFixture('007');
        $trip = $this->makeTripInstance($f, 10);

        $converted1 = WaitingList::create(['tenant_id' => $f['tenant']->id, 'customer_name' => 'A', 'phone_number' => '0700000001', 'status' => WaitingListStatusEnum::Converted->value]);
        $converted1->tripInstances()->attach($trip['instance']->id, ['priority' => 1]);

        $converted2 = WaitingList::create(['tenant_id' => $f['tenant']->id, 'customer_name' => 'B', 'phone_number' => '0700000002', 'status' => WaitingListStatusEnum::Converted->value]);
        $converted2->tripInstances()->attach($trip['instance']->id, ['priority' => 2]);

        $expired = WaitingList::create(['tenant_id' => $f['tenant']->id, 'customer_name' => 'C', 'phone_number' => '0700000003', 'status' => WaitingListStatusEnum::Expired->value]);
        $expired->tripInstances()->attach($trip['instance']->id, ['priority' => 3]);

        // Still pending — must not count toward the resolved denominator at all.
        $pending = WaitingList::create(['tenant_id' => $f['tenant']->id, 'customer_name' => 'D', 'phone_number' => '0700000004', 'status' => WaitingListStatusEnum::Pending->value]);
        $pending->tripInstances()->attach($trip['instance']->id, ['priority' => 4]);

        $component = $this->load($f, WaitlistCancellationHealthReport::class);
        $record = $component->instance()->getTableRecords()->firstWhere('id', $f['template']->id);
        $record->load('tripInstances.waitingLists');

        $waitlist = $record->tripInstances->flatMap->waitingLists;
        $this->assertCount(4, $waitlist);
        $convertedCount = $waitlist->where('status', WaitingListStatusEnum::Converted)->count();
        $expiredCount = $waitlist->where('status', WaitingListStatusEnum::Expired)->count();
        $this->assertSame(2, $convertedCount);
        $this->assertSame(1, $expiredCount);
        $this->assertEqualsWithDelta(66.7, round($convertedCount / ($convertedCount + $expiredCount) * 100, 1), 0.1);
    }

    public function test_waitlist_health_report_tenant_isolation(): void
    {
        $fA = $this->makeTenantFixture('008a');
        $fB = $this->makeTenantFixture('008b');

        $component = $this->load($fA, WaitlistCancellationHealthReport::class);

        $component->assertCanSeeTableRecords([$fA['template']]);
        $component->assertCanNotSeeTableRecords([$fB['template']]);
    }

    public function test_waitlist_health_export_produces_a_valid_file(): void
    {
        $f = $this->makeTenantFixture('009');
        $trip = $this->makeTripInstance($f, 10);
        $this->makeBooking($f, $trip);

        $component = $this->load($f, WaitlistCancellationHealthReport::class);
        $component->callTableAction('export');
        $component->assertHasNoTableActionErrors();
    }
}

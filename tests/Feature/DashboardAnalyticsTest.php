<?php

namespace Tests\Feature;

use App\Enums\PaymentType;
use App\Filament\Widgets\DashboardStatsOverview;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
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
 * Rewritten against the live path: the original test targeted a class named
 * App\Filament\Widgets\StatsOverviewWidget, which does not exist (it was merged into
 * DashboardStatsOverview per FIXES_LOG.md CRIT-001) and created bookings via the now-deleted
 * BookingService::createBooking() using `User` as the customer — the current schema separates
 * staff (`User`) from end-customers (`Customer`), and every live booking path uses the latter.
 * DashboardStatsOverview::canView() also gates on the acting user holding the agency_admin or
 * accountant role, which the original test never set up.
 */
class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;
    private CreateBookingService $createBookingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = new BookingService();
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

    public function test_stats_widget_computes_correct_sums_scoped_to_tenant(): void
    {
        // 1. Tenant A: one fully-paid 150.00 booking.
        $tenantA = Tenant::create(['name' => 'Agency North', 'slug' => 'agency-north-da', 'domain' => 'north-da.zatara.com']);
        $customerA = Customer::create(['name' => 'Customer A', 'phone' => '0799999991', 'tenant_id' => $tenantA->id]);
        $agentA = $this->makeAgencyAdmin($tenantA, '0791111111');

        $templateA = TripTemplate::create(['tenant_id' => $tenantA->id, 'title' => 'Trip A', 'base_price' => 150.00]);
        $instanceA = TripInstance::create([
            'tenant_id' => $tenantA->id,
            'trip_template_id' => $templateA->id,
            'start_date' => now()->addDays(2),
            'end_date' => now()->addDays(3),
            'available_seats' => 20,
            'status' => 'active',
        ]);
        $catA = TripPassengerCategory::create([
            'tenant_id' => $tenantA->id, 'trip_instance_id' => $instanceA->id,
            'name' => 'Adult', 'price' => 150.00, 'requires_seat' => true,
        ]);

        $bookingA = $this->createBookingService->execute([
            'tenant_id' => $tenantA->id,
            'trip_instance_id' => $instanceA->id,
            'customer_id' => $customerA->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $catA->id, 'first_name' => 'Passenger', 'last_name' => 'A1'],
            ],
        ]);

        $this->bookingService->recordPayment($bookingA, 150.00, 'cash', $agentA, PaymentType::FULL);

        // 2. Tenant B: one fully-paid 300.00 booking (isolation check).
        $tenantB = Tenant::create(['name' => 'Zatara Tours', 'slug' => 'zatara-tours-da', 'domain' => 'tours-da.zatara.com']);
        $customerB = Customer::create(['name' => 'Customer B', 'phone' => '0799999992', 'tenant_id' => $tenantB->id]);
        $agentB = $this->makeAgencyAdmin($tenantB, '0792222222');

        $templateB = TripTemplate::create(['tenant_id' => $tenantB->id, 'title' => 'Trip B', 'base_price' => 300.00]);
        $instanceB = TripInstance::create([
            'tenant_id' => $tenantB->id,
            'trip_template_id' => $templateB->id,
            'start_date' => now()->addDays(2),
            'end_date' => now()->addDays(3),
            'available_seats' => 20,
            'status' => 'active',
        ]);
        $catB = TripPassengerCategory::create([
            'tenant_id' => $tenantB->id, 'trip_instance_id' => $instanceB->id,
            'name' => 'Adult', 'price' => 300.00, 'requires_seat' => true,
        ]);

        $bookingB = $this->createBookingService->execute([
            'tenant_id' => $tenantB->id,
            'trip_instance_id' => $instanceB->id,
            'customer_id' => $customerB->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $catB->id, 'first_name' => 'Passenger', 'last_name' => 'B1'],
            ],
        ]);

        $this->bookingService->recordPayment($bookingB, 300.00, 'cash', $agentB, PaymentType::FULL);

        // 3. Widget scoped to Tenant A only sees Tenant A's figures.
        Filament::setTenant($tenantA, true);
        $this->actingAs($agentA);

        Livewire::test(DashboardStatsOverview::class)
            ->assertSee('حجوزات اليوم')
            ->assertSee('إجمالي الإيرادات')
            ->assertSee('150.00')
            ->assertDontSee('300.00');

        // 4. Widget scoped to Tenant B only sees Tenant B's figures.
        Filament::setTenant($tenantB, true);
        $this->actingAs($agentB);

        Livewire::test(DashboardStatsOverview::class)
            ->assertSee('300.00')
            ->assertDontSee('150.00');
    }
}

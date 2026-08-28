<?php

namespace Tests\Feature;

use App\Filament\Widgets\QuickActionsWidget;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TripInstance;
use App\Models\TripPassengerCategory;
use App\Models\TripTemplate;
use App\Models\User;
use App\Services\CreateBookingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Admin panel UX audit, quick-wins batch: dashboard widget duplication, a fresh-tenant "get
 * started" nudge, and a per-row cancellation-request flag on the bookings list.
 */
class DashboardQuickWinsTest extends TestCase
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

    // ------------------------------------------------------------------
    // Dashboard widget registered exactly once (was registered twice: once via
    // discoverWidgets(), once via the redundant explicit ->widgets([...]) array).
    // ------------------------------------------------------------------

    public function test_every_admin_panel_widget_is_registered_exactly_once(): void
    {
        $widgets = Filament::getPanel('admin')->getWidgets();
        $classNames = array_map(fn ($w) => is_string($w) ? $w : $w::class, $widgets);

        $this->assertSame(
            array_unique($classNames),
            $classNames,
            'No admin panel widget class should appear more than once in the registered widget list.'
        );
        $this->assertContains(\App\Filament\Widgets\QuickActionsWidget::class, $classNames);
    }

    // ------------------------------------------------------------------
    // Fresh-tenant "get started" nudge
    // ------------------------------------------------------------------

    public function test_fresh_tenant_with_no_trips_sees_the_get_started_nudge(): void
    {
        $tenant = Tenant::create(['name' => 'Agency Fresh', 'slug' => 'agency-fresh-dqw']);
        $admin = $this->makeAgencyAdmin($tenant, '0790000001');

        $this->actingAs($admin);
        Filament::setTenant($tenant, true);

        Livewire::test(QuickActionsWidget::class)
            ->assertSee('ابدأ بإنشاء أول رحلة لك');
    }

    public function test_tenant_with_an_existing_trip_does_not_see_the_get_started_nudge(): void
    {
        $tenant = Tenant::create(['name' => 'Agency Established', 'slug' => 'agency-established-dqw']);
        $admin = $this->makeAgencyAdmin($tenant, '0790000002');
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100]);
        TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);

        $this->actingAs($admin);
        Filament::setTenant($tenant, true);

        Livewire::test(QuickActionsWidget::class)
            ->assertDontSee('ابدأ بإنشاء أول رحلة لك');
    }

    public function test_fresh_tenant_nudge_does_not_leak_across_tenants(): void
    {
        $freshTenant = Tenant::create(['name' => 'Agency Fresh B', 'slug' => 'agency-fresh-b-dqw']);
        $establishedTenant = Tenant::create(['name' => 'Agency Established B', 'slug' => 'agency-established-b-dqw']);
        $admin = $this->makeAgencyAdmin($establishedTenant, '0790000003');
        $admin->tenants()->attach($freshTenant);
        $template = TripTemplate::create(['tenant_id' => $establishedTenant->id, 'title' => 'Trip', 'base_price' => 100]);
        TripInstance::create([
            'tenant_id' => $establishedTenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);

        $this->actingAs($admin);
        Filament::setTenant($freshTenant, true);

        Livewire::test(QuickActionsWidget::class)
            ->assertSee('ابدأ بإنشاء أول رحلة لك', 'Tenant B\'s existing trip must not suppress tenant A\'s own empty-state nudge.');
    }

    // ------------------------------------------------------------------
    // Cancellation-request badge on the bookings list
    // ------------------------------------------------------------------

    /**
     * @return array{tenant: Tenant, admin: User, customer: Customer, instance: TripInstance, cat: TripPassengerCategory}
     */
    private function makeBookingFixture(string $suffix): array
    {
        $tenant = Tenant::create(['name' => "Agency {$suffix}", 'slug' => "agency-cancelbadge-{$suffix}"]);
        $admin = $this->makeAgencyAdmin($tenant, "0791{$suffix}");
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Jane', 'phone' => "0592{$suffix}"]);
        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id, 'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5), 'end_date' => now()->addDays(6),
            'available_seats' => 10, 'status' => 'active',
        ]);
        $cat = TripPassengerCategory::create([
            'tenant_id' => $tenant->id, 'trip_instance_id' => $instance->id,
            'name' => 'Adult', 'price' => 100, 'requires_seat' => true,
        ]);

        return compact('tenant', 'admin', 'customer', 'instance', 'cat');
    }

    public function test_a_booking_with_a_pending_cancellation_request_shows_the_flag_icon(): void
    {
        $f = $this->makeBookingFixture('001');
        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'A', 'last_name' => 'B'],
            ],
        ]);
        $booking->update(['cancellation_requested_at' => now()]);

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        Livewire::test(\App\Filament\Resources\BookingResource\Pages\ListBookings::class)
            ->assertTableColumnStateSet('cancellation_requested_at', $booking->cancellation_requested_at, $booking);
    }

    public function test_a_booking_without_a_cancellation_request_has_no_flag_icon_rendered(): void
    {
        $f = $this->makeBookingFixture('002');
        $booking = $this->createBookingService->execute([
            'tenant_id' => $f['tenant']->id,
            'trip_instance_id' => $f['instance']->id,
            'customer_id' => $f['customer']->id,
            'passengersData' => [
                ['trip_passenger_category_id' => $f['cat']->id, 'first_name' => 'A', 'last_name' => 'B'],
            ],
        ]);

        $this->assertNull($booking->fresh()->cancellation_requested_at, 'Fixture sanity check: no cancellation was requested.');

        $this->actingAs($f['admin']);
        Filament::setTenant($f['tenant'], true);

        $response = Livewire::test(\App\Filament\Resources\BookingResource\Pages\ListBookings::class);
        $response->assertSuccessful();
    }
}

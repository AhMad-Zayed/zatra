<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed roles & permissions
        $this->seed(\Database\Seeders\RoleAndPermissionSeeder::class);

        // Create the panel_user role checked by Filament Shield middleware
        Role::firstOrCreate(['name' => 'panel_user']);

        // Dynamically create Shield-specific permissions checked by generated policies
        Permission::firstOrCreate(['name' => 'view_any_booking']);
        Permission::firstOrCreate(['name' => 'view_booking']);
        Permission::firstOrCreate(['name' => 'view_any_payment']);
        Permission::firstOrCreate(['name' => 'view_payment']);

        // FORGET CACHED PERMISSIONS so Spatie reloads them with the new dynamic ones
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_route_redirects_unauthenticated(): void
    {
        $response = $this->get('/admin');
        $response->assertRedirect('/admin/login');
    }

    public function test_tenant_isolation_in_admin_panel(): void
    {
        $tenant1 = Tenant::create(['name' => 'Agency One']);
        $tenant2 = Tenant::create(['name' => 'Agency Two']);

        $user1 = User::create(['name' => 'Agent One', 'phone' => '0791111111']);
        $user1->tenants()->attach($tenant1);
        $user1->assignRole(['booking_agent', 'panel_user']);
        $user1->givePermissionTo(['view_any_booking', 'view_booking']);

        $template1 = TripTemplate::create(['tenant_id' => $tenant1->id, 'title' => 'Trip One', 'base_price' => 100]);
        $instance1 = TripInstance::create([
            'tenant_id' => $tenant1->id,
            'trip_template_id' => $template1->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(2),
            'available_seats' => 10,
            'status' => 'active',
        ]);
        $booking1 = Booking::create([
            'tenant_id' => $tenant1->id,
            'user_id' => $user1->id,
            'trip_instance_id' => $instance1->id,
            'reference' => 'BK-ONE',
            'status' => BookingStatus::PENDING,
            'total_amount' => 100,
        ]);

        $template2 = TripTemplate::create(['tenant_id' => $tenant2->id, 'title' => 'Trip Two', 'base_price' => 200]);
        $instance2 = TripInstance::create([
            'tenant_id' => $tenant2->id,
            'trip_template_id' => $template2->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(2),
            'available_seats' => 10,
            'status' => 'active',
        ]);
        $booking2 = Booking::create([
            'tenant_id' => $tenant2->id,
            'user_id' => $user1->id,
            'trip_instance_id' => $instance2->id,
            'reference' => 'BK-TWO',
            'status' => BookingStatus::PENDING,
            'total_amount' => 200,
        ]);

        $this->actingAs($user1);
        
        $this->withoutExceptionHandling();
        // Visit URL to set route context
        $this->get("/admin/{$tenant1->id}/bookings")->assertSuccessful();

        Livewire::test(ListBookings::class)
            ->assertCanSeeTableRecords([$booking1])
            ->assertCanNotSeeTableRecords([$booking2]);
    }

    public function test_payment_immutability_in_admin_panel(): void
    {
        $tenant = Tenant::create(['name' => 'Agency']);
        $user = User::create(['name' => 'Agent', 'phone' => '0791111111']);
        $user->tenants()->attach($tenant);
        $user->assignRole(['booking_agent', 'panel_user']);
        $user->givePermissionTo(['view_any_payment', 'view_payment']);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(2),
            'available_seats' => 10,
            'status' => 'active',
        ]);
        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'trip_instance_id' => $instance->id,
            'reference' => 'BK-REF',
            'status' => BookingStatus::PENDING,
            'total_amount' => 100,
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'amount' => 50.00,
            'payment_method' => 'cash',
            'received_by' => $user->id,
            'type' => PaymentType::DEPOSIT,
        ]);

        $this->actingAs($user);
        
        // Visit URL to set route context
        $this->get("/admin/{$tenant->id}/payments")->assertSuccessful();

        Livewire::test(ListPayments::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }

    public function test_booking_cancel_action_in_admin_panel(): void
    {
        $tenant = Tenant::create(['name' => 'Agency']);
        $user = User::create(['name' => 'Agent', 'phone' => '0791111111']);
        $user->tenants()->attach($tenant);
        $user->assignRole(['booking_agent', 'panel_user']);
        $user->givePermissionTo(['view_any_booking', 'view_booking']);

        $template = TripTemplate::create(['tenant_id' => $tenant->id, 'title' => 'Trip', 'base_price' => 100]);
        $instance = TripInstance::create([
            'tenant_id' => $tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(1),
            'end_date' => now()->addDays(2),
            'available_seats' => 10,
            'status' => 'active',
        ]);
        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'trip_instance_id' => $instance->id,
            'reference' => 'BK-CANCEL',
            'status' => BookingStatus::PENDING,
            'total_amount' => 100,
        ]);

        $this->actingAs($user);
        
        // Visit URL to set route context
        $this->get("/admin/{$tenant->id}/bookings")->assertSuccessful();

        Livewire::test(ListBookings::class)
            ->callTableAction('cancel_booking', $booking);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => BookingStatus::CANCELLED->value,
        ]);
    }

    /**
     * Stakeholder-confirmed RBAC design (see the CustomerPolicy hotfix and the admin panel UX
     * audit): canAccessPanel() is deliberately a broad tenant-membership check, not a role/
     * permission gate -- the panel door stays open to any staff member attached to the tenant,
     * and individual resource policies (CustomerPolicy, BookingPolicy, etc.) are the real,
     * fine-grained gate. This used to assert a 403 at the door, which never matched that design
     * and never flipped when CustomerPolicy was added; corrected to assert what the confirmed
     * design actually produces: a 200 shell with a minimal experience (role-gated widgets like
     * DashboardStatsOverview's "إجمالي الإيرادات" revenue stat stay hidden via their own
     * canView()), not a 403.
     */
    public function test_roleless_customer_can_reach_the_admin_panel_shell_with_a_minimal_experience(): void
    {
        $tenant = Tenant::create(['name' => 'Agency']);
        $customer = User::create(['name' => 'Customer Booker', 'phone' => '0793333333']);
        $customer->tenants()->attach($tenant);

        // No roles assigned to the customer

        $this->actingAs($customer);

        $response = $this->get("/admin/{$tenant->id}");

        $response->assertStatus(200);
        $response->assertDontSee('إجمالي الإيرادات');
    }
}

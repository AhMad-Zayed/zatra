<?php

namespace Tests\Feature\Filament;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Customer;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\TripPricingTier;
use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use Livewire\Livewire;

class AdminBookingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;
    protected Customer $customer;
    protected TripInstance $tripInstance;
    protected TripPricingTier $tier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency A', 'slug' => 'agency-a', 'domain' => 'a.zatara.com']);
        
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@agency-a.com',
            'phone' => '0500000000',
            'password' => bcrypt('password'),
        ]);
        $this->admin->tenants()->attach($this->tenant->id);

        $this->customer = Customer::create([
            'name' => 'John Doe',
            'phone' => '+966500000000',
            'tenant_id' => $this->tenant->id,
        ]);

        $template = TripTemplate::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Test Trip',
            'base_price' => 500,
        ]);

        $this->tripInstance = TripInstance::create([
            'tenant_id' => $this->tenant->id,
            'trip_template_id' => $template->id,
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(10),
            'available_seats' => 20,
            'status' => 'Published',
        ]);

        $this->tier = TripPricingTier::create([
            'tenant_id' => $this->tenant->id,
            'trip_instance_id' => $this->tripInstance->id,
            'name' => 'Adult',
            'price' => 500,
        ]);
    }

    public function test_admin_can_create_booking_and_identities_are_allocated_correctly()
    {
        $this->actingAs($this->admin);
        
        // Ensure Filament tenant is set for the test
        \Filament\Facades\Filament::setTenant($this->tenant);

        // We test the hook directly since Filament UI testing requires panel configuration
        $page = new CreateBooking();
        
        $method = new \ReflectionMethod($page, 'mutateFormDataBeforeCreate');
        $method->setAccessible(true);
        
        $data = $method->invoke($page, [
            'trip_instance_id' => $this->tripInstance->id,
            'customer_id' => $this->customer->id,
        ]);

        $this->assertEquals($this->admin->id, $data['user_id']);
        $this->assertEquals($this->tenant->id, $data['tenant_id']);
    }
}

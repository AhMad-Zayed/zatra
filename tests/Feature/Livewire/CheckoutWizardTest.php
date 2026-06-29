<?php

namespace Tests\Feature\Livewire;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Livewire\CheckoutWizard;
use App\Models\Tenant;
use App\Models\TripTemplate;
use App\Models\TripInstance;
use App\Models\Customer;
use App\Models\TripPassengerCategory;
use App\Models\TripAddon;
use App\Services\CustomerOtpService;
use Illuminate\Support\Facades\RateLimiter;
use Mockery\MockInterface;

class CheckoutWizardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected TripInstance $tripInstance;
    protected TripPassengerCategory $pricingTier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Agency', 'slug' => 'test-agency', 'domain' => 'test.zatara.com']);
        
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
            'status' => 'active',
        ]);

        $this->pricingTier = TripPassengerCategory::create([
            'tenant_id' => $this->tenant->id,
            'trip_instance_id' => $this->tripInstance->id,
            'name' => 'Adult',
            'price' => 500,
            'min_age' => 12,
        ]);
    }

    public function test_it_successfully_verifies_otp_and_advances_wizard()
    {
        // Explicitly mock the CustomerOtpService to prevent SMS leaks
        $this->mock(CustomerOtpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendOtp')->once()->andReturnNull();
            $customer = Customer::create(['tenant_id' => $this->tenant->id, 'phone' => '+966500000000', 'name' => 'Mock Customer']);
            $mock->shouldReceive('verifyOtp')->once()->with(\Mockery::type(Tenant::class), '+966500000000', '123456')->andReturn($customer);
        });

        // Mock RateLimiter to prevent CI/CD throttling
        RateLimiter::shouldReceive('tooManyAttempts')->andReturn(false);
        RateLimiter::shouldReceive('hit')->andReturn(true);
        RateLimiter::shouldReceive('clear')->andReturn(true);

        Livewire::test(CheckoutWizard::class, ['tenant' => $this->tenant, 'tripInstance' => $this->tripInstance])
            ->set('form.phone', '+966500000000')
            ->call('submitPhone')
            ->assertSet('currentStep', 2)
            ->set('form.otp', '123456')
            ->call('verifyOtp')
            ->assertSet('currentStep', 3);
    }

    public function test_comprehensive_booking_flow_with_passenger_and_addons()
    {
        // 1. Phone submission & OTP Mocking
        $this->mock(CustomerOtpService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendOtp')->once()->andReturnNull();
            $customer = Customer::create(['tenant_id' => $this->tenant->id, 'phone' => '+966500000000', 'name' => 'John Doe']);
            $mock->shouldReceive('verifyOtp')->once()->with(\Mockery::type(Tenant::class), '+966500000000', '123456')->andReturn($customer);
        });

        RateLimiter::shouldReceive('tooManyAttempts')->andReturn(false);
        RateLimiter::shouldReceive('hit')->andReturn(true);
        RateLimiter::shouldReceive('clear')->andReturn(true);

        // Optional Addon for testing
        $addon = TripAddon::create([
            'tenant_id' => $this->tenant->id,
            'trip_instance_id' => $this->tripInstance->id,
            'name' => 'Extra Luggage',
            'price' => 100,
        ]);

        Livewire::test(CheckoutWizard::class, ['tenant' => $this->tenant, 'tripInstance' => $this->tripInstance])
            // STEP 1: Phone
            ->set('form.phone', '+966500000000')
            ->call('submitPhone')
            ->assertHasNoErrors()
            ->assertSet('currentStep', 2)

            // STEP 2: OTP
            ->set('form.otp', '123456')
            ->call('verifyOtp')
            ->assertHasNoErrors()
            ->assertSet('currentStep', 3)

            // STEP 3: Passengers
            // The component mounts with one empty passenger by default.
            ->set('form.passengers.0.name', 'John Doe Passenger')
            ->set('form.passengers.0.dob', '1990-01-01')
            ->set('form.passengers.0.trip_passenger_category_id', $this->pricingTier->id)
            ->call('submitPassengers')
            ->assertHasNoErrors()
            ->assertSet('currentStep', 4)

            // STEP 4: Addons
            ->call('toggleAddon', $addon->id)
            ->call('submitAddons')
            ->assertHasNoErrors()
            ->assertSet('currentStep', 5)

            // STEP 5: Finalize Booking (Cash payment to avoid Stripe redirect in this test)
            ->set('paymentMethod', 'cash')
            ->call('submitBooking')
            ->assertHasNoErrors();

        // Assert booking was created
        $this->assertDatabaseHas('bookings', [
            'tenant_id' => $this->tenant->id,
            'trip_instance_id' => $this->tripInstance->id,
        ]);
        
        $this->assertDatabaseHas('passengers', [
            'first_name' => 'John',
            'last_name' => 'Doe Passenger',
        ]);
    }
}

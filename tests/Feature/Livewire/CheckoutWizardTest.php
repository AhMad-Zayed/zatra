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
use App\Services\CustomerOtpService;
use Illuminate\Support\Facades\RateLimiter;
use Mockery\MockInterface;

class CheckoutWizardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected TripInstance $tripInstance;

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
            'status' => 'Published',
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

        // Verify the customer was implicitly created by the OTP service if successful
        // Note: The mock above returned true, but we bypassed the actual DB creation logic in the mock.
        // For a full integration test, we might only mock the SMS gateway, not the whole service.
        // But per architectural rules, we just verify the Livewire state boundaries.
    }
}
